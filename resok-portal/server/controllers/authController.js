const db = require('../config/database');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const crypto = require('crypto');
const { sendVerificationEmail, sendPasswordResetEmail, sendMemberWelcomeEmail } = require('../utils/email');

// Register new user
exports.register = async (req, res) => {
  try {
    const {
      email, password, title, firstName, middleName, surname,
      country, county, division, category, idType, idNumber, mobile
    } = req.body;

    if (!email || !password || !firstName || !surname || !mobile || !country || !county || !division || !category || !idNumber) {
      return res.status(400).json({ error: 'Required registration fields are missing' });
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return res.status(400).json({ error: 'Please enter a valid email address' });
    }
    if (!/^\+[1-9]\d{7,14}$/.test(mobile)) {
      return res.status(400).json({ error: 'Please enter a valid mobile number with country code' });
    }
    if (!/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,64}$/.test(password)) {
      return res.status(400).json({ error: 'Password must be 8-64 characters and include uppercase, lowercase, and a number' });
    }

    // Check if user exists
    const [existing] = await db.query('SELECT id FROM users WHERE email = ?', [email]);
    if (existing.length > 0) {
      return res.status(400).json({ error: 'Email already registered' });
    }

    // Hash password
    const passwordHash = await bcrypt.hash(password, 12);

    // Start transaction
    const connection = await db.getConnection();
    await connection.beginTransaction();

    try {
      // Create user
      const verificationToken = crypto.randomBytes(32).toString('hex');
      const emailVerified = process.env.REQUIRE_EMAIL_VERIFICATION === 'true' ? 0 : 1;
      const [userResult] = await connection.query(
        'INSERT INTO users (email, password_hash, email_verified, verification_token) VALUES (?, ?, ?, ?)',
        [email, passwordHash, emailVerified, emailVerified ? null : verificationToken]
      );
      const userId = userResult.insertId;

      // Create member profile
      await connection.query(
        `INSERT INTO member_profiles (
          user_id, title, first_name, middle_name, surname, country, county, 
          division, category, id_type, id_number, mobile, membership_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'documents_required')`,
        [userId, title, firstName, middleName, surname, country, county, 
         division, category, idType, idNumber, mobile]
      );

      await connection.commit();

      const welcomeMember = {
        title,
        firstName,
        middleName,
        surname,
        email,
        category,
        membershipId: null,
        renewalDue: 'Pending payment'
      };

      // Send welcome packet and verification email (async, don't wait)
      sendMemberWelcomeEmail(welcomeMember).catch(console.error);
      if (!emailVerified) sendVerificationEmail(email, verificationToken).catch(console.error);

      const payload = {
        message: emailVerified ? 'Registration successful.' : 'Registration successful! Please check your email to verify your account.',
        userId,
        requiresVerification: !emailVerified
      };

      if (emailVerified) {
        if (!process.env.JWT_SECRET || process.env.JWT_SECRET === 'change_me' || process.env.JWT_SECRET === 'change_this_to_a_long_random_secret') {
          return res.status(500).json({ error: 'JWT secret is not configured' });
        }
        payload.token = jwt.sign(
          { userId, email, role: 'member' },
          process.env.JWT_SECRET,
          { expiresIn: process.env.JWT_EXPIRE || '7d' }
        );
        payload.user = {
          id: userId,
          email,
          role: 'member',
          membershipStatus: 'documents_required',
          membershipId: null,
          cpdPoints: 0
        };
      }

      res.status(201).json(payload);

    } catch (err) {
      await connection.rollback();
      throw err;
    } finally {
      connection.release();
    }

  } catch (error) {
    console.error('Registration error:', error);
    res.status(500).json({ error: 'Registration failed. Please try again.' });
  }
};

// Login
exports.login = async (req, res) => {
  try {
    const { email, password } = req.body;
    if (!email || !password) return res.status(400).json({ error: 'Email and password are required' });

    // Get user with profile
    const [rows] = await db.query(
      `SELECT u.id, u.email, u.password_hash, u.email_verified, u.role,
              mp.membership_status, mp.membership_id, mp.cpd_points
       FROM users u
       LEFT JOIN member_profiles mp ON u.id = mp.user_id
       WHERE u.email = ?`,
      [email]
    );

    if (rows.length === 0) {
      return res.status(401).json({ error: 'Invalid credentials' });
    }

    const user = rows[0];

    // Check password
    const validPassword = await bcrypt.compare(password, user.password_hash);
    if (!validPassword) {
      return res.status(401).json({ error: 'Invalid credentials' });
    }

    // Check email verification
    if (!user.email_verified) {
      return res.status(403).json({ 
        error: 'Please verify your email before logging in',
        requiresVerification: true 
      });
    }

    // Generate JWT
    if (!process.env.JWT_SECRET || process.env.JWT_SECRET === 'change_me' || process.env.JWT_SECRET === 'change_this_to_a_long_random_secret') {
      return res.status(500).json({ error: 'JWT secret is not configured' });
    }
    const token = jwt.sign(
      { userId: user.id, email: user.email, role: user.role },
      process.env.JWT_SECRET,
      { expiresIn: process.env.JWT_EXPIRE || '7d' }
    );

    res.json({
      token,
      user: {
        id: user.id,
        email: user.email,
        role: user.role,
        membershipStatus: user.membership_status,
        membershipId: user.membership_id,
        cpdPoints: user.cpd_points
      }
    });

  } catch (error) {
    console.error('Login error:', error);
    res.status(500).json({ error: 'Login failed' });
  }
};

exports.forgotPassword = async (req, res) => {
  try {
    const { email } = req.body || {};
    if (!email) return res.status(400).json({ error: 'Email is required' });

    const [users] = await db.query('SELECT id FROM users WHERE email = ? LIMIT 1', [email]);
    if (users.length) {
      const token = crypto.randomBytes(32).toString('hex');
      const expires = new Date(Date.now() + 60 * 60 * 1000);
      await db.query('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?', [token, expires, users[0].id]);
      const baseUrl = process.env.FRONTEND_URL || 'https://www.resok.org/resok-portal/public';
      const url = `${baseUrl}/forgot-password?token=${token}`;
      sendPasswordResetEmail(email, token).catch(console.error);
      console.log(`Password reset link for ${email}: ${url}`);
    }

    return res.json({ message: 'If the email exists, a reset link has been queued.' });
  } catch (error) {
    console.error('Forgot password error:', error);
    return res.status(500).json({ error: 'Could not queue password reset' });
  }
};

exports.resetPassword = async (req, res) => {
  try {
    const { token, password } = req.body || {};
    if (!token || !password) return res.status(400).json({ error: 'Token and password are required' });
    const [users] = await db.query('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1', [token]);
    if (!users.length) return res.status(400).json({ error: 'Invalid or expired reset link' });
    const passwordHash = await bcrypt.hash(password, 12);
    await db.query('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?', [passwordHash, users[0].id]);
    return res.json({ message: 'Password updated. You can now log in.' });
  } catch (error) {
    console.error('Reset password error:', error);
    return res.status(500).json({ error: 'Could not reset password' });
  }
};

// Verify email
exports.verifyEmail = async (req, res) => {
  try {
    const { token } = req.params;
    
    const [users] = await db.query(
      'SELECT id FROM users WHERE verification_token = ? AND email_verified = FALSE',
      [token]
    );

    if (users.length === 0) {
      return res.status(400).json({ error: 'Invalid or expired verification link' });
    }

    await db.query(
      'UPDATE users SET email_verified = TRUE, verification_token = NULL WHERE id = ?',
      [users[0].id]
    );

    res.json({ message: 'Email verified successfully! You can now log in.' });

  } catch (error) {
    console.error('Email verification error:', error);
    res.status(500).json({ error: 'Verification failed' });
  }
};

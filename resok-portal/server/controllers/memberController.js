const db = require('../config/database');
const { generateMembershipId } = require('../utils/generateMembershipId');
const { sendAnnualMembershipEmail } = require('../utils/email');

function mapMember(row) {
  if (!row) return null;
  return {
    id: row.id,
    userId: row.user_id,
    title: row.title,
    firstName: row.first_name,
    middleName: row.middle_name,
    surname: row.surname,
    country: row.country,
    county: row.county,
    division: row.division,
    category: row.category,
    idType: row.id_type,
    idNumber: row.id_number,
    mobile: row.mobile,
    membershipStatus: row.membership_status,
    membershipId: row.membership_id,
    cpdPoints: row.cpd_points,
    renewalDue: row.renewal_due,
    reviewReason: row.review_reason,
    reviewedAt: row.reviewed_at
  };
}

async function getMe(req, res) {
  try {
    const userId = req.user?.userId;
    const [rows] = await db.query('SELECT * FROM member_profiles WHERE user_id = ? LIMIT 1', [userId]);
    return res.json(mapMember(rows[0]));
  } catch (error) {
    console.error('Member lookup error:', error);
    return res.status(500).json({ error: 'Could not load member profile' });
  }
}

async function updateMe(req, res) {
  try {
    const userId = req.user?.userId;
    const allowed = ['title', 'firstName', 'middleName', 'surname', 'country', 'county', 'division', 'category', 'idType', 'idNumber', 'mobile'];
    const body = req.body || {};
    const columns = [];
    const values = [];

    const columnMap = {
      firstName: 'first_name',
      middleName: 'middle_name',
      idType: 'id_type',
      idNumber: 'id_number'
    };

    allowed.forEach((key) => {
      if (Object.prototype.hasOwnProperty.call(body, key)) {
        columns.push(`${columnMap[key] || key} = ?`);
        values.push(body[key] || null);
      }
    });

    if (!columns.length) return res.status(400).json({ error: 'No profile fields provided' });

    values.push(userId);
    await db.query(`UPDATE member_profiles SET ${columns.join(', ')} WHERE user_id = ?`, values);
    const [rows] = await db.query('SELECT * FROM member_profiles WHERE user_id = ? LIMIT 1', [userId]);
    return res.json(mapMember(rows[0]));
  } catch (error) {
    console.error('Member update error:', error);
    return res.status(500).json({ error: 'Could not update member profile' });
  }
}

async function approveMember(req, res) {
  try {
    const memberId = req.params.id;
    if (process.env.ALLOW_APPROVE_WITHOUT_PAYMENT !== 'true') {
      const [payments] = await db.query(
        `SELECT COUNT(*) AS paid_count
         FROM payments
         WHERE member_profile_id = ? AND status = 'paid'`,
        [memberId]
      );
      if (!Number(payments[0]?.paid_count || 0)) {
        return res.status(409).json({ error: 'A confirmed payment is required before approval.' });
      }
    }
    const generated = generateMembershipId();
    await db.query(
      `UPDATE member_profiles
       SET membership_status = 'active',
           membership_id = COALESCE(membership_id, ?),
           renewal_due = DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
           review_reason = NULL,
           reviewed_at = NOW()
       WHERE id = ?`,
      [generated, memberId]
    );
    const [rows] = await db.query(
      `SELECT mp.*, u.email
       FROM member_profiles mp
       JOIN users u ON u.id = mp.user_id
       WHERE mp.id = ? LIMIT 1`,
      [memberId]
    );
    const member = mapMember(rows[0]);
    if (rows[0]?.email && member?.membershipId) {
      sendAnnualMembershipEmail({ ...member, email: rows[0].email }).catch(console.error);
    }
    return res.json(member);
  } catch (error) {
    console.error('Approve member error:', error);
    return res.status(500).json({ error: 'Could not approve member' });
  }
}

async function rejectMember(req, res) {
  try {
    const memberId = req.params.id;
    const reason = req.body?.reason || 'Please resubmit clearer documents.';
    await db.query(
      `UPDATE member_profiles
       SET membership_status = 'rejected', review_reason = ?, reviewed_at = NOW()
       WHERE id = ?`,
      [reason, memberId]
    );
    const [rows] = await db.query('SELECT * FROM member_profiles WHERE id = ? LIMIT 1', [memberId]);
    return res.json(mapMember(rows[0]));
  } catch (error) {
    console.error('Reject member error:', error);
    return res.status(500).json({ error: 'Could not reject member' });
  }
}

async function listReviewQueue(req, res) {
  try {
    const [rows] = await db.query(
      `SELECT mp.*, u.email
       FROM member_profiles mp
       JOIN users u ON u.id = mp.user_id
       WHERE mp.membership_status IN ('under_review', 'payment_required', 'documents_required', 'rejected')
       ORDER BY mp.updated_at DESC
       LIMIT 100`
    );
    return res.json(rows.map((row) => ({ ...mapMember(row), email: row.email })));
  } catch (error) {
    console.error('Review queue error:', error);
    return res.status(500).json({ error: 'Could not load review queue' });
  }
}

module.exports = { getMe, updateMe, approveMember, rejectMember, listReviewQueue };

const db = require('../config/database');
const { initiateStkPush } = require('../utils/mpesa');
const { mpesaConfigured } = require('../config/env');

function mapPayment(row) {
  return {
    id: row.id,
    userId: row.user_id,
    memberProfileId: row.member_profile_id,
    amount: Number(row.amount),
    currency: row.currency,
    method: row.method,
    type: row.payment_type,
    phone: row.phone,
    status: row.status,
    reference: row.reference,
    providerReference: row.provider_reference,
    date: row.created_at
  };
}

async function createPayment(req, res) {
  try {
    const userId = req.user?.userId;
    const { amount, phone, type } = req.body || {};
    if (!amount || Number(amount) <= 0) return res.status(400).json({ error: 'Valid amount is required' });
    if (!phone || !/^\+?[1-9]\d{7,14}$/.test(String(phone))) {
      return res.status(400).json({ error: 'A valid payment phone number is required' });
    }

    const [members] = await db.query('SELECT id FROM member_profiles WHERE user_id = ? LIMIT 1', [userId]);
    const memberProfileId = members[0]?.id || null;
    const reference = `RESOK-${Date.now().toString(36).toUpperCase()}`;

    const canUseOfflinePayments = process.env.ALLOW_OFFLINE_PAYMENTS === 'true';
    if (!mpesaConfigured() && !canUseOfflinePayments) {
      return res.status(503).json({ error: 'M-Pesa is not configured. Payment cannot be accepted.' });
    }

    let provider = { status: 'skipped' };
    if (mpesaConfigured()) {
      provider = await initiateStkPush({ amount: Number(amount), phone, reference });
    }

    const status = 'pending';
    const method = provider.checkoutRequestId ? 'M-Pesa' : 'Manual review';
    const [result] = await db.query(
      `INSERT INTO payments
       (user_id, member_profile_id, amount, currency, method, payment_type, phone, status, reference, provider_reference)
       VALUES (?, ?, ?, 'KES', ?, ?, ?, ?, ?, ?)`,
      [userId, memberProfileId, Number(amount), method, type || 'Membership', phone || null, status, reference, provider.checkoutRequestId || null]
    );

    if (memberProfileId && status === 'paid') {
      await db.query(
        `UPDATE member_profiles
         SET membership_status = CASE
           WHEN membership_status IN ('documents_required', 'payment_required', 'rejected') THEN 'under_review'
           ELSE membership_status
         END
         WHERE id = ?`,
        [memberProfileId]
      );
    }

    const [rows] = await db.query('SELECT * FROM payments WHERE id = ? LIMIT 1', [result.insertId]);
    return res.status(201).json(mapPayment(rows[0]));
  } catch (error) {
    console.error('Payment error:', error);
    return res.status(500).json({ error: 'Could not create payment' });
  }
}

async function listPayments(req, res) {
  try {
    const userId = req.user?.userId;
    const [rows] = await db.query('SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [userId]);
    return res.json(rows.map(mapPayment));
  } catch (error) {
    console.error('List payments error:', error);
    return res.status(500).json({ error: 'Could not load payments' });
  }
}

async function mpesaCallback(req, res) {
  try {
    const callback = req.body?.Body?.stkCallback;
    if (!callback?.CheckoutRequestID) {
      return res.status(400).json({ error: 'Invalid M-Pesa callback' });
    }

    const status = Number(callback.ResultCode) === 0 ? 'paid' : 'failed';
    await db.query(
      'UPDATE payments SET status = ? WHERE provider_reference = ?',
      [status, callback.CheckoutRequestID]
    );

    if (status === 'paid') {
      await db.query(
        `UPDATE member_profiles mp
         JOIN payments p ON p.member_profile_id = mp.id
         SET mp.membership_status = CASE
           WHEN mp.membership_status IN ('documents_required', 'payment_required', 'rejected') THEN 'under_review'
           ELSE mp.membership_status
         END
         WHERE p.provider_reference = ?`,
        [callback.CheckoutRequestID]
      );
    }

    return res.json({ received: true });
  } catch (error) {
    console.error('M-Pesa callback error:', error);
    return res.status(500).json({ error: 'Could not process M-Pesa callback' });
  }
}

module.exports = { createPayment, listPayments, mpesaCallback };

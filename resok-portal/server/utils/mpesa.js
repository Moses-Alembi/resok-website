const axios = require('axios');

function normalizePhone(phone) {
  return String(phone || '').replace(/^\+/, '');
}

async function getAccessToken() {
  const key = process.env.MPESA_CONSUMER_KEY;
  const secret = process.env.MPESA_CONSUMER_SECRET;
  if (!key || !secret) return null;

  const baseUrl = process.env.MPESA_BASE_URL || 'https://sandbox.safaricom.co.ke';
  const auth = Buffer.from(`${key}:${secret}`).toString('base64');
  const { data } = await axios.get(`${baseUrl}/oauth/v1/generate?grant_type=client_credentials`, {
    headers: { Authorization: `Basic ${auth}` }
  });
  return data.access_token;
}

async function initiateStkPush({ amount, phone, reference }) {
  const token = await getAccessToken();
  const shortcode = process.env.MPESA_SHORTCODE;
  const passkey = process.env.MPESA_PASSKEY;
  const callbackUrl = process.env.MPESA_CALLBACK_URL;
  if (!token || !shortcode || !passkey || !callbackUrl) return { status: 'skipped' };

  const baseUrl = process.env.MPESA_BASE_URL || 'https://sandbox.safaricom.co.ke';
  const timestamp = new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
  const password = Buffer.from(`${shortcode}${passkey}${timestamp}`).toString('base64');

  const { data } = await axios.post(
    `${baseUrl}/mpesa/stkpush/v1/processrequest`,
    {
      BusinessShortCode: shortcode,
      Password: password,
      Timestamp: timestamp,
      TransactionType: process.env.MPESA_TRANSACTION_TYPE || 'CustomerPayBillOnline',
      Amount: Math.round(Number(amount)),
      PartyA: normalizePhone(phone),
      PartyB: shortcode,
      PhoneNumber: normalizePhone(phone),
      CallBackURL: callbackUrl,
      AccountReference: reference,
      TransactionDesc: 'ReSoK membership payment'
    },
    { headers: { Authorization: `Bearer ${token}` } }
  );

  return {
    status: 'pending',
    checkoutRequestId: data.CheckoutRequestID,
    merchantRequestId: data.MerchantRequestID,
    response: data
  };
}

module.exports = { initiateStkPush };

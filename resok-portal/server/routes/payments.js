const express = require("express");
const { auth } = require("../middleware/auth");
const { createPayment, listPayments, mpesaCallback } = require("../controllers/paymentController");

const router = express.Router();

router.post("/mpesa/callback", mpesaCallback);
router.get("/", auth, listPayments);
router.post("/", auth, createPayment);

module.exports = router;

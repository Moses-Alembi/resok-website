const express = require("express");
const { auth, requireAdmin } = require("../middleware/auth");
const { getMe, updateMe, approveMember, rejectMember, listReviewQueue } = require("../controllers/memberController");

const router = express.Router();

router.get("/me", auth, getMe);
router.patch("/me", auth, updateMe);
router.get("/review-queue", auth, requireAdmin, listReviewQueue);
router.post("/:id/approve", auth, requireAdmin, approveMember);
router.post("/:id/reject", auth, requireAdmin, rejectMember);

module.exports = router;

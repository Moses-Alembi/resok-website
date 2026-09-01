const express = require('express');
const { auth, requireAdmin } = require('../middleware/auth');
const upload = require('../middleware/upload');
const { listDocuments, listMemberDocuments, uploadDocuments, deleteDocument, downloadDocument } = require('../controllers/documentController');

const router = express.Router();

router.get('/', auth, listDocuments);
router.get('/member/:memberProfileId', auth, requireAdmin, listMemberDocuments);
router.post('/', auth, upload.array('documents', 10), uploadDocuments);
router.get('/:id/download', auth, downloadDocument);
router.delete('/:id', auth, deleteDocument);

module.exports = router;

const db = require('../config/database');
const fs = require('fs/promises');
const path = require('path');

function mapDocument(row) {
  return {
    id: row.id,
    userId: row.user_id,
    memberProfileId: row.member_profile_id,
    type: row.doc_type,
    name: row.original_name,
    filename: row.filename,
    status: row.status,
    date: row.created_at,
    downloadUrl: `/api/documents/${row.id}/download`
  };
}

function storagePath(row) {
  const docType = String(row.doc_type || 'other').replace(/[^a-z0-9_-]/gi, '_').slice(0, 80);
  return path.join(__dirname, '../../uploads', docType, row.filename);
}

async function listDocuments(req, res) {
  try {
    const [rows] = await db.query('SELECT * FROM documents WHERE user_id = ? ORDER BY created_at DESC', [req.user.userId]);
    return res.json(rows.map(mapDocument));
  } catch (error) {
    console.error('List documents error:', error);
    return res.status(500).json({ error: 'Could not load documents' });
  }
}

async function listMemberDocuments(req, res) {
  try {
    if (req.user?.role !== 'admin') return res.status(403).json({ error: 'Admin access required' });
    const [rows] = await db.query(
      'SELECT * FROM documents WHERE member_profile_id = ? ORDER BY created_at DESC',
      [req.params.memberProfileId]
    );
    return res.json(rows.map(mapDocument));
  } catch (error) {
    console.error('List member documents error:', error);
    return res.status(500).json({ error: 'Could not load member documents' });
  }
}

async function uploadDocuments(req, res) {
  try {
    const files = req.files || [];
    if (!files.length) return res.status(400).json({ error: 'No files uploaded' });

    const [members] = await db.query('SELECT id FROM member_profiles WHERE user_id = ? LIMIT 1', [req.user.userId]);
    const memberProfileId = members[0]?.id || null;
    const docs = [];

    for (const file of files) {
      const [result] = await db.query(
        `INSERT INTO documents (user_id, member_profile_id, doc_type, filename, original_name, mime_type, file_size, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')`,
        [
          req.user.userId,
          memberProfileId,
          req.body.docType || 'Uploaded Document',
          file.filename,
          file.originalname,
          file.mimetype,
          file.size
        ]
      );
      const [rows] = await db.query('SELECT * FROM documents WHERE id = ? LIMIT 1', [result.insertId]);
      docs.push(mapDocument(rows[0]));
    }

    if (memberProfileId) {
      await db.query(
        `UPDATE member_profiles
         SET membership_status = CASE
           WHEN membership_status IN ('documents_required', 'rejected') THEN 'payment_required'
           ELSE membership_status
         END
         WHERE id = ?`,
        [memberProfileId]
      );
    }

    return res.status(201).json(docs);
  } catch (error) {
    console.error('Upload documents error:', error);
    return res.status(500).json({ error: 'Could not upload documents' });
  }
}

async function deleteDocument(req, res) {
  try {
    const [rows] = await db.query('SELECT * FROM documents WHERE id = ? AND user_id = ? LIMIT 1', [req.params.id, req.user.userId]);
    if (!rows.length) return res.status(404).json({ error: 'Document not found' });
    await db.query('DELETE FROM documents WHERE id = ? AND user_id = ?', [req.params.id, req.user.userId]);
    await fs.unlink(storagePath(rows[0])).catch(() => {});
    return res.status(204).end();
  } catch (error) {
    console.error('Delete document error:', error);
    return res.status(500).json({ error: 'Could not delete document' });
  }
}

async function downloadDocument(req, res) {
  try {
    const params = [req.params.id];
    let scope = '';
    if (req.user?.role !== 'admin') {
      scope = ' AND user_id = ?';
      params.push(req.user.userId);
    }

    const [rows] = await db.query(`SELECT * FROM documents WHERE id = ?${scope} LIMIT 1`, params);
    if (!rows.length) return res.status(404).json({ error: 'Document not found' });

    return res.download(storagePath(rows[0]), rows[0].original_name);
  } catch (error) {
    console.error('Download document error:', error);
    return res.status(500).json({ error: 'Could not download document' });
  }
}

module.exports = { listDocuments, listMemberDocuments, uploadDocuments, deleteDocument, downloadDocument };

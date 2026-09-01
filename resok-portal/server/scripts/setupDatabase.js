const fs = require("fs/promises");
const path = require("path");
const mysql = require("mysql2/promise");
const bcrypt = require("bcryptjs");
const { validateEnv } = require("../config/env");

async function run() {
  validateEnv();

  const schemaPath = path.join(__dirname, "../schema.sql");
  const dbName = process.env.DB_NAME;
  const safeDbName = `\`${String(dbName).replace(/`/g, "``")}\``;
  const schema = (await fs.readFile(schemaPath, "utf8"))
    .replace(
      /^CREATE DATABASE IF NOT EXISTS\s+resok_portal\b.*?;/m,
      `CREATE DATABASE IF NOT EXISTS ${safeDbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
    )
    .replace(/^USE\s+resok_portal\s*;/m, `USE ${safeDbName};`);
  const adminEmail = process.env.ADMIN_EMAIL;
  const adminPassword = process.env.ADMIN_PASSWORD;

  const connection = await mysql.createConnection({
    host: process.env.DB_HOST,
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD || "",
    multipleStatements: true
  });

  try {
    await connection.query(schema);

    if (adminEmail && adminPassword) {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adminEmail)) {
        throw new Error("ADMIN_EMAIL must be a valid email address.");
      }
      if (!/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{12,64}$/.test(adminPassword)) {
        throw new Error("ADMIN_PASSWORD must be 12-64 characters and include uppercase, lowercase, and a number.");
      }

      await connection.query(`USE ${safeDbName}`);
      const passwordHash = await bcrypt.hash(adminPassword, 12);
      await connection.query(
        `INSERT INTO users (email, password_hash, email_verified, role)
         VALUES (?, ?, TRUE, 'admin')
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), email_verified = TRUE, role = 'admin'`,
        [adminEmail, passwordHash]
      );
      console.log(`Database ready. Admin user ensured: ${adminEmail}`);
    } else {
      console.log("Database ready. Set ADMIN_EMAIL and ADMIN_PASSWORD to create or update an admin user.");
    }
  } finally {
    await connection.end();
  }
}

run().catch((error) => {
  console.error(error?.stack || error?.message || String(error));
  if (Array.isArray(error?.errors)) {
    error.errors.forEach((item) => {
      console.error(item?.stack || item?.message || String(item));
    });
  }
  process.exit(1);
});

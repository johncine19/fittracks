-- Promote existing staff users to admin
UPDATE users SET role = 'admin' WHERE role = 'staff';

-- Remove 'staff' from the role enum
ALTER TABLE users MODIFY COLUMN role ENUM('admin','trainer','member') NOT NULL;

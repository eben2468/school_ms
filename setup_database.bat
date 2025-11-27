@echo off
"C:\xampp\mysql\bin\mysql.exe" -u root < "config/schema.sql"
echo Database setup complete!
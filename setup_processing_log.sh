#!/bin/bash

# Setup script for processing log functionality

echo "Setting up processing log table..."

# Check if config exists
if [ ! -f "db/dbconfig.php" ]; then
    echo "Error: db/dbconfig.php not found!"
    echo "Please configure database settings first."
    exit 1
fi

# Extract database credentials (this is a simple approach)
# For production, consider using environment variables

echo "Creating processing_log table..."
echo "Please run the following SQL command on your database:"
echo ""
echo "-------------------------------------------------------------------"
cat <<'EOF'
CREATE TABLE `processing_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `operation_type` varchar(50) NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `groupname` varchar(50) DEFAULT NULL,
  `parser_key` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'started',
  `sentences_count` int(11) DEFAULT 0,
  `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `operation_type` (`operation_type`),
  KEY `source_id` (`source_id`),
  KEY `groupname` (`groupname`),
  KEY `status` (`status`),
  KEY `started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOF
echo "-------------------------------------------------------------------"
echo ""
echo "Or run: mysql -u username -p database_name < createtables.sql"
echo ""
echo "For Elasticsearch, the processing-logs index will be created automatically"
echo "when the first log entry is written."
echo ""
echo "Done!"

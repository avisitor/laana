BASE=/var/www/html/noiiolelo
cd $BASE/scripts
source $BASE/.env
cat $BASE/wordcounts.sql | mysql -u $DB_USER -p$DB_PASSWORD $DB_DATABASE | sed -n '2p' | tee $BASE/data/wordcounts.json

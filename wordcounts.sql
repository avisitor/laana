SELECT
    JSON_OBJECTAGG(bucket, count) AS histogram_json
FROM (
    SELECT
        CASE
            WHEN word_count BETWEEN 1 AND 2 THEN '  1:1-2'
            WHEN word_count BETWEEN 3 AND 5 THEN '  2:3-5'
            WHEN word_count BETWEEN 6 AND 10 THEN '  3:6-10'
            WHEN word_count BETWEEN 11 AND 15 THEN '  4:11-15'
            WHEN word_count BETWEEN 16 AND 20 THEN '  5:16-20'
            WHEN word_count BETWEEN 21 AND 25 THEN '  6:21-25'
            WHEN word_count BETWEEN 26 AND 30 THEN '  7:26-30'
            WHEN word_count BETWEEN 31 AND 35 THEN '  8:31-35'
            WHEN word_count BETWEEN 36 AND 40 THEN '  9:36-40'
            WHEN word_count BETWEEN 41 AND 50 THEN ' 10:41-50'
            WHEN word_count BETWEEN 51 AND 60 THEN ' 11:51-60'
            WHEN word_count BETWEEN 61 AND 70 THEN ' 12:61-70'
            WHEN word_count BETWEEN 71 AND 90 THEN ' 13:71-80'
            WHEN word_count BETWEEN 81 AND 90 THEN ' 14:81-90'
            WHEN word_count BETWEEN 91 AND 100 THEN ' 15:91-100'
            WHEN word_count BETWEEN 101 AND 150 THEN ' 16:101-150'
            WHEN word_count BETWEEN 151 AND 200 THEN ' 17:151-200'
            WHEN word_count BETWEEN 201 AND 250 THEN ' 18:201-250'
            WHEN word_count BETWEEN 251 AND 300 THEN ' 19:251-300'
            WHEN word_count BETWEEN 301 AND 350 THEN ' 20:301-350'
            WHEN word_count BETWEEN 351 AND 400 THEN ' 21:351-400'
            WHEN word_count BETWEEN 401 AND 450 THEN ' 21:401-450'
            WHEN word_count BETWEEN 451 AND 500 THEN ' 22:451-500'
            WHEN word_count > 500 THEN '>500'
            ELSE 'unknown'
        END AS bucket,
        COUNT(*) AS count
    FROM (
        SELECT
            sentenceID,
            -- Word count = spaces + 1
            LENGTH(TRIM(hawaiianText))
            - LENGTH(REPLACE(TRIM(hawaiianText), ' ', ''))
            + 1 AS word_count
        FROM sentences
        WHERE hawaiianText IS NOT NULL
          AND TRIM(hawaiianText) <> ''
    ) AS wc
    GROUP BY bucket
) AS buckets;

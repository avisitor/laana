#!/usr/bin/env bash
# Download/refresh the GNIS source data into ./data/ (see ../data/hawaiian_place_names.md).
# Skips files that already exist; pass --force to re-download everything.
set -euo pipefail
cd "$(dirname "$0")/data"
FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

need() { [ "$FORCE" = 1 ] || [ ! -s "$1" ]; }

# 1. Current GNIS Domestic Names for HI (USGS StagedProducts on S3).
if need DomesticNames_HI.txt; then
    curl -sL --max-time 300 'https://prd-tnm.s3.amazonaws.com/StagedProducts/GeographicNames/DomesticNames/DomesticNames_HI_Text.zip' -o DomesticNames_HI_Text.zip
    unzip -o -q DomesticNames_HI_Text.zip
    mv Text/DomesticNames_HI.txt DomesticNames_HI.txt
    rm -rf Text DomesticNames_HI_Text.zip
    echo "DomesticNames_HI.txt refreshed"
fi

# 2. geodata.hawaii.gov ArcGIS "Place Names" layer (federal subset with county + coords).
if need page0.json; then
    BASE='https://geodata.hawaii.gov/arcgis/rest/services/HistoricCultural/MapServer/2/query'
    OPTS='where=1%3D1&outFields=feature_name%2Cfeature_class%2Ccounty_name%2Cprim_lat_dec%2Cprim_long_dec&returnGeometry=false&f=json&resultRecordCount=5000'
    curl -s --max-time 300 "${BASE}?${OPTS}&resultOffset=0" -o page0.json
    curl -s --max-time 300 "${BASE}?${OPTS}&resultOffset=5000" -o page1.json
    echo "page0.json/page1.json refreshed (check they contain features)"
fi

# 3. 2017 GNIS National File (figshare mirror of geonames.usgs.gov), filtered to HI.
if need hawaii_2017.txt; then
    curl -sL --max-time 600 'https://ndownloader.figshare.com/files/9167146' -o NationalFile_20170601.txt.gz
    gunzip -kf NationalFile_20170601.txt.gz
    awk -F'|' '$4=="HI"' NationalFile_20170601.txt > hawaii_2017.txt
    if [ "$FORCE" = 1 ]; then
        rm -f NationalFile_20170601.txt NationalFile_20170601.txt.gz
    fi
    echo "hawaii_2017.txt refreshed ($(wc -l < hawaii_2017.txt) rows)"
fi

ls -la

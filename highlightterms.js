function normalizeString( input ) {
    var a = ['ō', 'ī', 'ē', 'ū', 'ā', 'Ō', 'Ī', 'Ē', 'Ū', 'Ā', '‘', 'ʻ'];
    var b = ['o', 'i', 'e', 'u', 'a', 'O', 'I', 'E', 'U', 'A', '', ''];
    var output = input
        .replace(a[0], b[0])
        .replace(a[1], b[1])
        .replace(a[2], b[2])
        .replace(a[3], b[3])
        .replace(a[4], b[4])
        .replace(a[5], b[5])
        .replace(a[6], b[6])
        .replace(a[7], b[7])
        .replace(a[8], b[8])
        .replace(a[9], b[9])
        .replace(a[10], b[10])
        .replace(a[11], b[11]);
    return output;
}

function highlight( body ) {
        var pattern = '<?=$pattern?>';
        var word = '<?=$word?>';
        var normalizedWord = normalizeString( word );
        // body is an array of sentence descriptions
        body.forEach( function(row) {
            var source = row['sourcename'];
            var authors = row['authors'];
            var link = row['link'];
            var sourcelink = "<a href='" + link + "' target='_blank'>" + source + "</a>";
            var rawsentence = sentence = row['hawaiiantext'];
            var result = "";
            var target = (pattern == 'exact') ? word : normalizedWord;
            var tw = target;
            const targetwords = target.split(/(\s+)/);
            const words = sentence.split(/(\s+)/);
            words.forEach( function(w) {
                var normalized = normalizeString( w );
                targetwords.forEach( function(tw) {
                    var sourceword = ( tw.match( "/[ōīēūāŌĪĒŪĀ‘ʻ]/" ) ) ? w : normalized;
                    //error_log( "index.php: comparing $tw to $sourceword" );
                    if( !tw.localeCompare( sourceword ) ) {
                        //error_log( "index.php: matched $tw to $sourceword" );
                        w = '<strong>' + w + '</strong>';
                    }
                });
                result += w + ' ';
            });
            sentence = result;
            var translate = "https://translate.google.com/?sl=auto&tl=en&op=translate&text=" +
                       rawsentence;
            var p = '<div class="hawaiiansentence">' +
                  '<p>' + sentence + '</p>' +
                  '<p class="source">' + sourcelink + '</p>' +
                  '<p class="source">' + authors + '</p>' +
                  '<p class="source"><a target="_blank" href="' + translate + '"> + translate</a></p>' +
            '</div>' +
            '<hr>';
            $('div.sentences').append( p );
        });
}

        /*
    $container.on( 'load.infiniteScroll', function( event, body ) {
    });
        */

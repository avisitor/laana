<?php
require_once __DIR__ . '/lib/provider.php';
$providerName = $_GET['provider'] ?? 'Laana' /*'Elasticsearch'*/;
$provider = getProvider( $providerName );
//require_once __DIR__ . '/lib/utils.php';
$word = isset($_GET['search']) ? $_GET['search'] : "";
$normalizedWord = $provider->normalizeString( $word );
$pattern = isset($_GET['searchpattern']) ? $_GET['searchpattern'] : "";
if( !$pattern ) {
    $modes = $provider->getAvailableSearchModes();
    $pattern = array_keys( $modes )[0];
}
$from = isset($_GET['from']) ? $_GET['from'] : "";
$to = isset($_GET['to']) ? $_GET['to'] : "";
$groupname = isset($_GET['group']) ? $_GET['group'] : "";
if( $word ) {
    if( $pattern == 'regex' ) {
        $word = urlencode( $word );
    }
}
$doSources = isset( $_GET['sources'] );
$doResources = isset( $_GET['resources'] );
$nodiacriticals = ( isset( $_REQUEST['nodiacriticals'] ) && $_REQUEST['nodiacriticals'] == 1 ) ? 1 : 0;
$nodiacriticalsparam = ($nodiacriticals) ? "&nodiacriticals=1" : "";
$order = isset($_GET['order']) ? $_GET['order'] : "rand";
$provider->debuglog( "pattern: $pattern; word: $word; order: $order; nodiacriticals: $nodiacriticals" );
$base = preg_replace( '/\?.*/', '', $_SERVER["REQUEST_URI"] );
//error_log( var_export( $_SERVER, true ) );
?>
<!DOCTYPE html>
<html lang="en" class="">
    <head>
        <?php include 'common-head.html'; ?>
        <title><?=$word?> - Noiʻiʻōlelo</title>
        <link rel="stylesheet" type="text/css" href="./static/bouncy-loader.css">
        <script>
            var pattern ="<?=$pattern?>";
            var orderBy ="<?=$order?>";
        </script>
    </head>

    <body id=fadein onload="changeid()">
        <div class="headerlinks">
        <?= ($doSources) ? '<a href="' . $base . '"><button class="linkbutton" type="button">Home</button></a>' : '<a href="?sources"><button class="linkbutton" type="button">Sources</button></a>' ?>
        <?= ($doResources) ? '<a href="' . $base . '"><button class="linkbutton" type="button">Home</button></a>' : '<a href="?resources"><button class="linkbutton" type="button">Resources</button></a>' ?>
    </div>

       <a href="<?=$base?>" class="nostyle">
         <div class="titletext nostyle">
               <h1>Noiʻiʻōlelo</h1>
             <p>Hawaiian Text Search</p>
         </div>
       </a>

<?php if( $word ) {  ?>
       <a class="slide" href="#">Help</a>
       <div id="fade-help" class="box"></div>
<?php } ?>


<?php if( !($doSources || $doResources) ) { ?>
       
       <center>
         <form method="get">
          <input type="hidden" id="search-pattern" name="searchpattern" value="<?=$pattern?>" />
          <input type="hidden" id="nodiacriticals" name="nodiacriticals" value="<?=$nodiacriticals?>" />
          <input type="hidden" id="order" name="order" value="<?=$order?>" />
          <input type="hidden" id="from" name="from" value="<?=$from?>" />
          <input type="hidden" id="to" name="to" value="<?=$to?>" />
          <div class="search-bar">
              <input name="search" id="searchbar" type="text" size="40" style="width:40em;" placeholder="Type a word or pattern in Hawaiian" value='<?=urldecode( $word )?>' required />
                <button type="submit" class="search-button">
                    <i>Go!</i>
                </button>
            </div>
         </form>

		<button type="button" class="dd-button" onclick="displaySearchOptions()">Search Options</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ā')">ā</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ē')">ē</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ī')">ī</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ō')">ō</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ū')">ū</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('‘')">‘</button>
        
        <div id="search-options" style="display:none;">
            <table>
                <tbody>
                <tr>
                    <td>
            <label for="searchtype">Search type:</label>
			<select id="searchtype" class="dd-menu" onchange="patternSelected(this)">
                <?php foreach ($provider->getAvailableSearchModes() as $mode => $description) { ?>
                    <option value="<?=$mode?>" <?=($pattern == $mode) ? 'selected' : ''?>><?=$description?></option>
                <?php } ?>
			</select>
                    </td>
                    <td>
          <label for="nodiacriticals" style="padding-left:1em;">No diacriticals</label>
          <input id="checkbox-nodiacriticals" type="checkbox" name="checkbox-nodiacriticals" <?=($nodiacriticals)?'checked':''?> onclick="setNoDiacriticals()"/>
                    </td>
                    <td style="padding-left:1em;"><label for="frombox" style="width:5em;">From year:</label>
                        <input type="text" cols="4" name="frombox" id="frombox" style="width:3em;" onchange="fromChanged(this)" value="<?=$from?>" /></td>
                </tr>
                <tr>
                    <td colspan="2">
            <label for="select-order">Sort by:</label>
			<select id="select-order" class="dd-menu" value ="<?=$order?>" onchange="orderSelected(this)">
                <option value="rand">Random</option>
                <option value="alpha">Alphabetical</option>
                <option value="alpha desc">Alphabetical descending</option>
                <option value="date">By date</option>
                <option value="date desc">By date descending</option>
                <option value="source">By source</option>
                <option value="source desc">By source descending</option>
                <option value="length">By length</option>
                <option value="length desc">By length descending</option>
                <option value="none">None</option>
			</select>
                    </td>
                    <td style="padding-left:1em;"><label for="tobox" style="width:5em;">To year:</label>
                        <input type="text" cols="4" name="tobox" id="tobox" style="width:3em;" onchange="toChanged(this)" value="<?=$to?>" /></td>
                </tr>
                </tbody>
            </table>
		</div>
       </center>

<?php } // !($doSources || $doResources) ?>

<?php
     $groups = $provider->getLatestSourceDates();
     //$groupcounts = $provider->getSourceGroupCounts();
     $groupdates = [];
     foreach( $groups as $group ) {
         $groupdates[$group['groupname']] = $group['date'];
     }

     if( $doSources ) {
?>
    
<div class="sentences" style="padding:.6em;">
    Type into the Search box to narrow the sources shown. Click on the item under Name to go to the source location, to the item under HTML for the version stored in Noiiolelo and under Plain for a text-only version. Hover on the item under HTML or Plain to see the document inline. Dismiss the inline window clicking anywhere outside of it. You can scroll the inline window and also search in it with Ctrl-F/Cmd-F. 
</div>

<?php } ?>

 <div class="sentences" id="sentences">
     
<?php if( !$word ) { ?>
    <?php if( $doSources ) { ?>

         <script>
	     $(document).ready(function () {
             //$("#<?=$pattern?>").prop( "checked", true );
             $('#table').DataTable({
                 paging: false,
                 order: [[ 1, "asc" ]],
                 ordering: true,
                 /*
                    responsive: {
                    details: {
                    display: $.fn.dataTable.Responsive.display.childRow,
                    type: ''
                    }
                    },
                  */
             }
             );
	      });
          
          const delay = 1000;
          $(document).ready(function() {
              $(".context").each( function( i, obj ) {
                  $(obj).on( 'mouseenter', function() {
                      if( $(obj).prop('hovertimeout') != null ) {
                          clearTimeout( $(obj).prop('hovertimeout') );
                      }
                      let sourceid = $(obj).attr('sourceid');
                      let simplified = parseInt( $(obj).attr('simplified') );
                      $(obj).prop('hovertimeout', setTimeout( function() {
                          showHoverBox( sourceid, simplified );
                      }, delay ) );
                  });
                  $(obj).on('mouseleave', function () {
                      if( $(obj).prop( 'hovertimeout') != null ) {
                          clearTimeout( $(obj).prop('hovertimeout') );
                          $(obj).prop( 'hovertimeout', null );
                      }
                  });
              });
          });
          
          function showHoverBox( sourceid, s ) {
              let url = "rawpage.php?id=" + sourceid;
              if( s ) {
                  url += "&simplified";
              }
              hoverBox.src = url;
              hoverBox.style.display = 'block';
          }
          function hideHoverBox() {
              // Hide the hover box on mouseout
              var hoverBox = document.getElementById('hoverBox');
              hoverBox.style.display = 'none';
              hoverBox.src = '';
          }
          document.getElementById('sentences').onclick = hideHoverBox;
          document.getElementsByTagName('body').item(0).onclick = hideHoverBox;
          document.getElementsByTagName('html').item(0).onclick = hideHoverBox;
         </script>

         <iframe id="hoverBox" class="draggable" width="70%" height="70%" style="left: 28%;top: 5%;">
         </iframe>
         <table id="table" class="sourcetable"><thead>
             <tr><th class="source-group">Group (ID)</th><th class="source-name">Name</th><th class="source-date">Date</th><th class="source-html">HTML</th><th class="source-plain">Plain</th><th class="source-authors">Authors</th><th class="source-sentences text-end text-xs-right text-right">Sentences</th></tr>
         </thead><tbody>

<?php
             $rows = $provider->getSources( $groupname );
             //var_export( $rows );
             foreach( $rows as $row ) {
                 $source = $row['sourcename'];
                 $short = substr( $source, 0, 20 );
                 $sourceid = $row['sourceid'];
                 $plainlink = "<a class='context fancy' sourceid='$sourceid' simplified='1' href='rawpage.php?simplified&id=$sourceid' target='_blank'>Plain</a>";
                 $htmllink = "<a class='context fancy' sourceid='$sourceid' simplified='0' href='rawpage.php?id=$sourceid' target='_blank'>HTML</a>";
                 $authors = $row['authors'];
                 $link = $row['link'];
                 $date = $row['date'];
                 $group = $row['groupname'] . " ($sourceid)";
                 $sourcelink = "<a class='fancy' href='$link' target='_blank'>$source</a>";
                 $count = $row['sentencecount'];
?>

        <tr>
            <td class="hawaiiansentence"><?=$group?></td>
            <td class="hawaiiansentence"><?=$sourcelink?></td>
            <td class="hawaiiansentence"><?=$date?></td>
            <td class="hawaiiansentence"><?=$htmllink?></td>
            <td class="hawaiiansentence"><?=$plainlink?></td>
            <td class='authors'><?=$authors?></td>
            <td class="hawaiiansentence" style="text-align:right;"><?=$count?></td>
        </tr>

    <?php } // if doSources ?>

      </tbody></table>

<?php
    } else if( $doResources ) {
        include 'resources.html';
    } else {
        // No word, not sources, not resources
        $stats = $provider->getCorpusStats();
        $sentenceCount = number_format($stats['sentence_count']);
        $sourceCount = number_format($stats['source_count']);
        $totalGroupSourceCounts = $provider->getTotalSourceGroupCounts();
        $provider->debuglog( $totalGroupSourceCounts, "totalGroupSourceCounts" );
        $nupepaTotalCount = number_format($totalGroupSourceCounts['nupepa']);
        include 'overview.html';
    }
} else { 
    /* Word passed */
    $options = [];
    if( $nodiacriticals ) {
        $options['nodiacriticals'] = true;
    }
    if( $order) {
        $options['orderby'] = $order;
    }
?>
    <div><span id="matchcount"></span></div><br />

    <script>
     function recordSearch( search, searchpattern, count, order, elapsedTime ) {
         let params = {
             search: search,
             searchpattern: searchpattern,
             count: count,
             order: order,
             elapsed: elapsedTime,
         };
         $.post("ops/recordsearch",
                params,
                function( data, status ) {
                    console.log( 'recordSearch: ' + data + " (" + status + ")" );
                });
     }
     function reportCount( count ) {
         let div = document.getElementById('matchcount');
         div.innerHTML = count.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") + ' matching sentences';
     }
     $(document).ready(function() {
         let term = '<?=urldecode( $word )?>';
         //term = term.replace( '"', '\"' );
         let countLoaded = false;
         let startTime = new Date();
         let url;
         let count = 0;
         url = 'ops/getPageHtml.php?word=<?=$word?>&pattern=<?=$pattern?>&page={{#}}&order=<?=$order?>&from=<?=$from?>&to=<?=$to?><?=$nodiacriticalsparam?>&provider=<?=$provider->getName()?>';
         let $container = $('.sentences').infiniteScroll({
             path: url,
             history: false,
             prefill: true,
             debug: true,
             //responseBody: 'json',
             append: 'div.hawaiiansentence',
             //status: '.page-load-status',
             status: '.preloader',
             onInit: function() {
                 this.on( 'request', function() {
                     console.log('Infinite Scroll request');
                 });
                 this.on( 'load', function( body, path, response ) {
                     console.log('Infinite Scroll load %o',response);
                 });
                 this.on( 'last', function( body, path ) {
                     console.log('Infinite Scroll last, ' + this.loadCount + ' pages loaded');
                     if( !countLoaded ) {
                         countLoaded = true;
                         const now = new Date();
                         console.log( "now: " + now + ", start: " + startTime );
                         const elapsedTime = now - startTime;
                         recordSearch( term, "<?=$pattern?>", count, "<?=$order?>", elapsedTime );
                         reportCount( 0 );
                     }
                     setTimeout( function() {
                         $(".preloader").css( "display", "none" );
                     }, 100 );
                 });
                 this.on( 'error', function( error, path, response ) {
                     console.log('Infinite Scroll error %o',error);
                 });
                 this.on( 'history', function( title, path ) {
                     console.log('Infinite Scroll history changed to ' + path);
                 });
                 this.on( 'scrollThreshold', function() {
                     console.log('Infinite Scroll at bottom');
                 });
                 this.on( 'append', function( body, path, items, response ) {
                     console.log('Infinite Scroll append body:%o path:%o items:%o response:%o', body, path, items, response)
                     count = items.length;
                     console.log( count + " items returned" );
                     if( count < <?=$provider->pageSize?> ) { // Less than a page
                         console.log( 'Turning off loadOnScroll' );
                         this.option( {
                             //loadOnScroll : false,
                             scrollThreshold : false,
                             prefill : false,
                         } );
                     }
                     if( !countLoaded ) {
                         countLoaded = true;
                         const elapsedTime = new Date() - startTime;
                         startTime = new Date();
                         recordSearch( term, "<?=$pattern?>", count, "<?=$order?>", elapsedTime );
                         if( count < <?=$provider->pageSize?> ) { // Less than a page
                             reportCount( count );
                             return;
                         }
                         // More than a page of results, so have to query
                         url = 'ops/resultcount.php?search='
                               + '<?=$word?>&searchpattern=<?=$pattern?>';
                         fetch( url )
                             .then(response => response.text())
                             .then(count => {
                                 reportCount( count );
                             })
                             .catch(error => console.error('Error fetching match count:', error));
                     }
                 });
             },
         });
         
     });
    </script>
<!-- -
    <div class="page-load-status">
        <div class="loader-ellips infinite-scroll-request">
            <span class="loader-ellips__dot"></span>
            <span class="loader-ellips__dot"></span>
            <span class="loader-ellips__dot"></span>
            <span class="loader-ellips__dot"></span>
        </div>
    </div>
  -->
	<div class="preloader">
		<span></span>
		<span></span>
		<span></span>
		<span></span>
		<span></span>
	</div>
    
<?php } // word ?>
    </div>
    </body>
</html>

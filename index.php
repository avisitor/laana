<?php
include 'db/funcs.php';
$word = isset($_GET['search']) ? $_GET['search'] : "";
$normalizedWord = normalizeString( $word );
$pattern = isset($_GET['searchpattern']) ? $_GET['searchpattern'] : "any";
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
debuglog( "pattern: $pattern; word: $word; order: $order; nodiacriticals: $nodiacriticals" );
$base = preg_replace( '/\?.*/', '', $_SERVER["REQUEST_URI"] );
//error_log( var_export( $_SERVER, true ) );
?>
<!DOCTYPE html>
<html lang="en" class="">
    <head>
        <?php include 'common-head.html'; ?>
        <title><?=$word?> - Noiʻiʻōlelo</title>
        <script>
            var pattern ="<?=$pattern?>";
            var orderBy ="<?=$order?>";
        </script>
    </head>

    <body id=fadein onload="changeid()">
        <div class="headerlinks">
        <?= ($doSources) ? '<a href="/"><button class="linkbutton" type="button">Home</button></a>' : '<a href="?sources"><button class="linkbutton" type="button">Sources</button></a>' ?>
        <?= ($doResources) ? '<a href="/"><button class="linkbutton" type="button">Home</button></a>' : '<a href="?resources"><button class="linkbutton" type="button">Resources</button></a>' ?>
    </div>

       <a href="<?=$base?>" class="nostyle">
         <div class="titletext nostyle">
               <h1>Noiʻiʻōlelo</h1>
             <p>Hawaiian Text Search</p>
         </div>
       </a>

<?php
     if( $word ) {
?>
       <a class="slide" href="#">Help</a>
       <div id="fade-help" class="box"></div>
<?php
      }
?>
     
<?php if( !($doSources || $doResources) ) { ?>
       
       <center>
         <form method="get">
          <input type="hidden" id="search-pattern" name="searchpattern" value="<?=$pattern?>" />
          <input type="hidden" id="nodiacriticals" name="nodiacriticals" value="<?=$nodiacriticals?>" />
          <input type="hidden" id="order" name="order" value="<?=$order?>" />
          <div class="search-bar">
              <input name="search" id="searchbar" type="text" size="40" style="width:40em;" placeholder="Type a word or pattern in Hawaiian" value="<?=urldecode( $word )?>" required />
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
            <div>
            <label for="searchtype">Search type:</label>
			<select id="searchtype" class="dd-menu" onchange="patternSelected(this)">
                <option value="any">Any</option>
                <option value="all">All</option>
                <option value="exact">Exact</option>
                <option value="regex">Regex</option>
			</select>
            &nbsp;
          <label for="nodiacriticals">No diacriticals</label>
          <input id="checkbox-nodiacriticals" type="checkbox" name="checkbox-nodiacriticals" <?=($nodiacriticals)?'checked':''?> onclick="setNoDiacriticals()"/>
            </div><div>
            <label for="select-order">Sort by:</label>
			<select id="select-order" class="dd-menu" value ="<?=$order?>" onchange="orderSelected(this)">
                <option value="rand">Random</option>
                <option value="alpha">Alphabetical</option>
                <option value="date">By date</option>
                <option value="date desc">By date descending</option>
                <option value="source">By source</option>
                <option value="source desc">By source descending</option>
                <option value="length">By length</option>
                <option value="length desc">By length descending</option>
			</select>
            </div>
		</div>
       </center>

<?php } ?>

<?php
     $laana = new Laana();
     $groups = $laana->getLatestSourceDates();
     $groupdates = [];
     foreach( $groups as $group ) {
         $groupdates[$group['groupname']] = $group['date'];
     }
     if( $doSources ) {
?>
    
<div class="sentences" style="padding:.6em;">
    Type into the Search box to narrow the sources shown. Click on the item under Name to go to the source location, to the item under HTML for the version stored in Noiiolelo and under Plain for a text-only version. Hover on the item under HTML or Plain to see the document inline. Dismiss the inline window with the close button at the top right or by clicking anywhere outside the inline window. You can scroll the inline window and also search in it with Ctrl-F/Cmd-F. 
</div>

<?php
     }
?>

 <div class="sentences" id="sentences">
     
     <?php
     if( !$word ) {
         if( $doSources ) {
     ?>

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
              let url = "rawpage?id=" + sourceid;
              if( s ) {
                  url += "&simplified";
              }
              fetch( url )
                  .then(response => response.text())
                  .then(pageContents => {
                      // Create and display the hover box
                      let hoverBox = document.getElementById('hoverBox');
                      let hoverBody = document.getElementById('hoverBody');
                      let styles = "<style>a {background:unset !important; color: blue !important;}</style>";
                      //hoverBody.innerHTML = "<div>" + styles + pageContents + "</div>";
                      hoverBody.innerHTML = pageContents;
                      hoverBox.style.display = 'block';
                      hoverBox.scrollTop = 0;
                      hoverBody.scrollTop = 0;
                  })
                  .catch(error => console.error('Error fetching content:', error));
          }
          function hideHoverBox() {
              // Hide the hover box on mouseout
              var hoverBox = document.getElementById('hoverBox');
              hoverBox.style.display = 'none';
          }
          document.getElementById('sentences').onclick = hideHoverBox;
          document.getElementsByTagName('body').item(0).onclick = hideHoverBox;
         </script>

         <div id="hoverBox">
             <div style="text-align:right;"><button onClick="hideHoverBox()">X</button></div>
             <div id="hoverBody"></div>
         </div>
         <table id="table"><thead>
             <tr><th>Group (ID)</th><th style="width:10em;">Name</th><th style="15em;">HTML</th><th>Plain</th><th>Authors</th><th style="text-align:right;">Sentences</th></tr>
         </thead><tbody>

<?php
$rows = $laana->getSources();
//var_export( $rows );
foreach( $rows as $row ) {
    $source = $row['sourcename'];
    $short = substr( $source, 0, 20 );
    $sourceid = $row['sourceid'];
    $plainlink = "<a class='context fancy' sourceid='$sourceid' simplified='1' href='rawpage?simplified&id=$sourceid' target='_blank'>Plain</a>";
    $htmllink = "<a class='context fancy' sourceid='$sourceid' simplified='0' href='rawpage?id=$sourceid' target='_blank'>HTML</a>";
    $authors = $row['authors'];
    $link = $row['link'];
    $group = $row['groupname'] . " ($sourceid)";
    $sourcelink = "<a class='fancy' href='$link' target='_blank'>$source</a>";
    $count = $row['count'];
?>

    <tr>
        <td class="hawaiiansentence"><?=$group?></td>
        <td class="hawaiiansentence"><?=$sourcelink?></td>
        <td class="hawaiiansentence"><?=$htmllink?></td>
        <td class="hawaiiansentence"><?=$plainlink?></td>
        <td class='authors'><?=$authors?></td>
        <td class="hawaiiansentence" style="text-align:right;"><?=$count?></td>
    </tr>

<?php
}
?>

         </tbody></table>

<?php
} else if( $doResources ) {
    include 'resources.html';
} else {
    $laana = new Laana();
    $sentenceCount = $sourceCount = 0;
    $sentenceCount = number_format($laana->getSentenceCount());
    $sourceCount = number_format($laana->getSourceCount());
    include 'overview.html';
}
} else {
    /* Word passed */
    $options = [];
    if( $nodiacriticals ) {
        $options['nodiacriticals'] = true;
    }
    if( $orderBy ) {
        $options['orderby'] = $orderBy;
    }
    $count = 0;
    $params = $options;
    $params['count'] = true;
    $count = number_format( $laana->getMatchingSentenceCount( $word, $pattern, -1, $params ) );
?>
<div><?=$count?> matching sentences</div><br />

    <script>
     $(document).ready(function() {
         var pageNumber = 0;
         let url = 'ops/getPageHtml.php?word=<?=$word?>&pattern=<?=$pattern?>&page={{#}}&order=<?=$order?><?=$nodiacriticalsparam?>';
         let $container = $('.sentences').infiniteScroll({
             path: url,
             history: false,
             prefill: true,
             debug: true,
             //responseBody: 'json',
             append: 'div.hawaiiansentence',
             status: '.page-load-status',
         });
     });
    </script>

    <div class="page-load-status">
        <div class="loader-ellips infinite-scroll-request">
            <span class="loader-ellips__dot"></span>
            <span class="loader-ellips__dot"></span>
            <span class="loader-ellips__dot"></span>
            <span class="loader-ellips__dot"></span>
        </div>
    </div>

<?php } ?>
 </div>
    </body>
</html>

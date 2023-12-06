<?php
include 'db/funcs.php';
$word = isset($_GET['search']) ? $_GET['search'] : "";
$normalizedWord = normalizeString( $word );
$pattern = isset($_GET['searchpattern']) ? $_GET['searchpattern'] : "";
if( !$pattern ) {
    //if ($word == $normalizedWord) {
        $pattern = 'any';
    //} else {
    //    $pattern = 'exact';
    //}
}
if( $word ) {
    if( $pattern == 'regex' ) {
        $word = urlencode( $word );
    }
}
$doSources = isset( $_GET['sources'] );
$doResources = isset( $_GET['resources'] );
$nodiacriticals = ( isset( $_REQUEST['nodiacriticals'] ) && $_REQUEST['nodiacriticals'] == 1 ) ? 1 : 0;
$nodiacriticalsparam = ($nodiacriticals) ? "&nodiacriticals=1" : "";
debuglog( "pattern: $pattern; word: $word" );
$base = preg_replace( '/\?.*/', '', $_SERVER["REQUEST_URI"] );
//error_log( var_export( $_SERVER, true ) );
?>
<!DOCTYPE html>
<html lang="en" class="">
    <head>
        <?php include 'common-head.html'; ?>
        <title><?=$word?> - Noiʻiʻōlelo</title>
    </head>

    <body id=fadein onload="changeid()">
        <script>
            var pattern ="<?=$pattern?>";
        </script>
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
 <?php if( !($doSources || $doResources) ) { ?>
       
       <center>
         <form method="get">
          <input type="hidden" id="search-pattern" name="searchpattern" value="<?=$pattern?>" />
          <input type="hidden" id="nodiacriticals" name="nodiacriticals" value="<?=$nodiacriticals?>" />
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
        <button class="character-insert-button" type="button" onclick="insertcharacter('ʻ')">ʻ</button>
        <div id="search-options" style="display:none;">
			<ul class="dd-menu">
          <!--
			    <li class="character-insert-button" onclick="insertcharacter(' #minlength ')">Minimum Length (number)</li>
			    <li class="character-insert-button" onclick="insertcharacter(' #maxlength ')">Maximum Length (number)</li>
          -->
          <li>Match type:&nbsp;&nbsp;
          <label for="exact">Exact</label>
          <input id="exact" type="radio" name="pattern" value="Exact" onclick="setPattern('exact')"/>
          &nbsp;&nbsp;
          <label for="order">Order</label>
          <input id="order" type="radio" name="pattern" value="Order"  onclick="setPattern('order')"/>
          &nbsp;&nbsp;
          <label for="any">Any</label>
          <input id="any" type="radio" name="pattern" value="Any"  onclick="setPattern('any')"/>
          &nbsp;&nbsp;
          <label for="regex">Regex</label>
          <input id="regex" type="radio" name="pattern" value="Regex" onclick="setPattern('regex')"/>
          &nbsp;
          <label for="nodiacriticals">No diacriticals</label>
          <input id="checkbox-nodiacriticals" type="checkbox" name="checkbox-nodiacriticals" <?=($nodiacriticals)?'checked':''?> onclick="setNoDiacriticals()"/>
          </li>
			</ul>
		</div>
       </center>
 <?php } ?>

<?php
     if( $doSources ) {
     ?>
<div class="sentences" style="padding:.6em;">
    Type into the Search box to narrow the sources shown. Click on the item under Name to go to the source location, to the item under HTML for the version stored in Noiiolelo and under Plain for a text-only version. Hover on the item under HTML or Plain to see the document inline. Dismiss the inline window with the close button at the top right or by clicking anywhere outside the inline window. You can scroll the inline window and also search in it with Ctrl-F/Cmd-F. 
</div>
<?php
     }
?>

 <div class="sentences" id="sentences">

     <script>
         $(document).ready(function() {
             let el = document.getElementById( pattern );
             if( el ) {
                 el.checked = true;
             }
         });
     </script>
     
     <?php
     if( !$word ) {
         if( $doSources ) {
     ?>
         <script>
	     $(document).ready(function () {
             $("#<?=$pattern?>").prop( "checked", true );
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
             $laana = new Laana();
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
?>
        <script>
        $(document).ready(function() {
             var pageNumber = 0;
             let url = 'ops/getPageHtml.php?word=<?=$word?>&pattern=<?=$pattern?>&page={{#}}<?=$nodiacriticalsparam?>';
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
            <!-- -
            <p class="infinite-scroll-request">Loading...</p>
 -->
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

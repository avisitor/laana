<?php
require_once __DIR__ . '/lib/provider.php';
$provider = getProvider();
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
$doGrammar = isset( $_GET['grammar'] );
$doStats = isset( $_GET['stats'] );
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
        <?php if ($doStats) { ?>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <?php } ?>
        <script>
            var pattern ="<?=$pattern?>";
            var orderBy ="<?=$order?>";
        </script>
    </head>

    <body id=fadein onload="changeid()">
        <div class="headerlinks">
        <?= ($doSources) ? '<a href="' . $base . '"><button class="linkbutton" type="button">Home</button></a>' : '<a href="?sources"><button class="linkbutton" type="button">Sources</button></a>' ?>
        <?= ($doResources) ? '<a href="' . $base . '"><button class="linkbutton" type="button">Home</button></a>' : '<a href="?resources"><button class="linkbutton" type="button">Resources</button></a>' ?>
        <?= ($doGrammar) ? '<a href="' . $base . '"><button class="linkbutton" type="button">Home</button></a>' : '<a href="?grammar"><button class="linkbutton" type="button">Grammar</button></a>' ?>
        <?= ($doStats) ? '<a href="' . $base . '"><button class="linkbutton" type="button">Home</button></a>' : '<a href="?stats"><button class="linkbutton" type="button">Stats</button></a>' ?>
    </div>

       <a href="<?=$base?>" class="nostyle">
         <div class="titletext nostyle">
               <h1>Noiʻiʻōlelo</h1>
             <p>Hawaiian Text Search</p>
         </div>
       </a>

<?php if( $word && !($doGrammar || $doSources || $doResources || $doStats) ) {  ?>
       <a class="slide" href="#">Help</a>
       <div id="fade-help" class="box" data-provider="<?=$provider->getName()?>"></div>
<?php } ?>


<?php if( !($doSources || $doResources || $doGrammar || $doStats) ) { ?>
       
       <center style="max-width:100vw; overflow-x:hidden;">
         <form method="get" style="max-width:100%; overflow-x:hidden;">
          <input type="hidden" id="search-pattern" name="searchpattern" value="<?=$pattern?>" />
          <input type="hidden" id="provider" name="provider" value="<?=$provider->getName()?>" />
          <input type="hidden" id="nodiacriticals" name="nodiacriticals" value="<?=$nodiacriticals?>" />
          <input type="hidden" id="order" name="order" value="<?=$order?>" />
          <input type="hidden" id="from" name="from" value="<?=$from?>" />
          <input type="hidden" id="to" name="to" value="<?=$to?>" />
          <div class="search-bar" style="max-width:95vw;">
              <input name="search" id="searchbar" type="text" size="40" style="width:100%; max-width:40em; box-sizing:border-box; border-radius:6px;" placeholder="Type a word or pattern in Hawaiian" value='<?=urldecode( $word )?>' required />
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
        
        <div id="search-options" style="display:none; font-size:0.8em; max-width:100%; padding:0.5em;">
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:0.5em; max-width:500px; margin:0 auto;">
                <div>
                    <label for="searchtype" style="font-size:0.85em; display:block;">Search type:</label>
                    <select id="searchtype" class="dd-menu" onchange="patternSelected(this)" style="font-size:0.85em; width:100%; max-width:100%;">
                        <?php 
                        $availableModes = $provider->getAvailableSearchModes();
                        $modeOrder = [];
                        $providerName = $provider->getName();
                        if ($providerName === 'Elasticsearch') {
                            $modeOrder = ['match', 'matchall', 'phrase', 'regex', 'hybrid'];
                        } else if ($providerName === 'Postgres') {
                            $modeOrder = ['exact', 'any', 'all', 'near', 'regex', 'hybrid'];
                        } else {
                            $modeOrder = ['exact', 'any', 'all', 'regex'];
                        }
                        
                        foreach ($modeOrder as $mode) {
                            if (isset($availableModes[$mode])) {
                                $description = $availableModes[$mode];
                                $selected = ($pattern == $mode) ? 'selected' : '';
                                echo "<option value=\"$mode\" $selected>$description</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label for="frombox" style="font-size:0.85em; display:block;">From year:</label>
                    <input type="text" name="frombox" id="frombox" style="width:4em; font-size:0.85em; box-sizing:border-box;" onchange="fromChanged(this)" value="<?=$from?>" />
                </div>
                <div>
                    <label for="tobox" style="font-size:0.85em; display:block;">To year:</label>
                    <input type="text" name="tobox" id="tobox" style="width:4em; font-size:0.85em; box-sizing:border-box;" onchange="toChanged(this)" value="<?=$to?>" />
                </div>
                <div>
                    <label for="select-order" style="font-size:0.85em; display:block;">Sort by:</label>
                    <select id="select-order" class="dd-menu" value="<?=$order?>" onchange="orderSelected(this)" style="font-size:0.85em; width:100%; max-width:100%;">
                        <option value="rand">Random</option>
                        <option value="alpha">Alpha</option>
                        <option value="alpha desc">Alpha desc</option>
                        <option value="date">Date</option>
                        <option value="date desc">Date desc</option>
                        <option value="source">Source</option>
                        <option value="source desc">Source desc</option>
                        <option value="length">Length</option>
                        <option value="length desc">Length desc</option>
                        <option value="none">None</option>
                    </select>
                </div>
                <div>
                    <label for="nodiacriticals" style="font-size:0.85em; display:block;">No diacriticals</label>
                    <input id="checkbox-nodiacriticals" type="checkbox" name="checkbox-nodiacriticals" <?=($nodiacriticals)?'checked':''?> onclick="setNoDiacriticals()"/>
                </div>
                <div>
                    <label for="provider-select" style="font-size:0.85em; display:block;">Provider:</label>
                    <select id="provider-select" class="dd-menu" onchange="providerSelected(this)" style="font-size:0.85em; width:100%; max-width:10em;">
                        <?php 
                            // Dynamically render known providers
                            require_once __DIR__ . '/lib/provider.php';
                            $known = getKnownProviders();
                            foreach (array_keys($known) as $provName) {
                                $selected = ($provider->getName() === $provName) ? 'selected' : '';
                                echo "<option value=\"$provName\" $selected>$provName</option>";
                            }
                        ?>
                    </select>
                </div>
            </div>
		</div>
       </center>

<?php } // !($doSources || $doResources || $doGrammar || $doStats) ?>

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

<?php if( $doGrammar ) { ?>
<div style="padding:.3em 0.5em; text-align:center; color:#333; background-color:rgba(255,255,255,0.85); margin:0.5em auto; max-width:fit-content;">
    Select a provider and a grammar pattern to find matching sentences.
</div>

<center style="max-width:100vw; overflow-x:hidden;">
    <form id="grammar-form" style="max-width:fit-content; background-color:rgba(255,255,255,0.85); padding:0.5em; border-radius:8px;">
        <div style="display:flex; flex-direction:column; gap:0.5em;">
            <!-- First row: Provider, Pattern, Go button -->
            <div style="display:flex; gap:1em; align-items:flex-end; justify-content:center;">
                <div>
                    <label for="grammar-provider-select" style="display:block; font-size:0.85em; color:#333; font-weight:600;">Provider:</label>
                    <select id="grammar-provider-select" class="dd-menu" onchange="grammarProviderSelected(this)" style="font-size:0.85em; width:10em;">
                        <?php 
                            require_once __DIR__ . '/lib/provider.php';
                            $known = getKnownProviders();
                            // In grammar view, default to MySQL
                            $grammarProvider = isset($_REQUEST['provider']) ? $_REQUEST['provider'] : 'MySQL';
                            foreach (array_keys($known) as $provName) {
                                $selected = ($grammarProvider === $provName) ? 'selected' : '';
                                echo "<option value=\"$provName\" $selected>$provName</option>";
                            }
                        ?>
                    </select>
                </div>
                <div>
                    <label for="grammar-pattern-select" style="display:block; font-size:0.85em; color:#333; font-weight:600;">Grammar Pattern:</label>
                    <select id="grammar-pattern-select" class="dd-menu" style="font-size:0.85em; width:20em;">
                        <option value="">Loading patterns...</option>
                    </select>
                </div>
                <div>
                    <button type="button" id="grammar-go-button" class="search-button" onclick="loadGrammarResults()" disabled>
                        <i>Go!</i>
                    </button>
                </div>
            </div>
            <!-- Second row: From year, To year, Sort by -->
            <div style="display:flex; gap:1em; align-items:flex-end; justify-content:center;">
                <div>
                    <label for="grammar-from-year" style="display:block; font-size:0.85em; color:#333; font-weight:600;">From year:</label>
                    <input type="text" id="grammar-from-year" style="width:8em; font-size:0.85em; box-sizing:border-box;" value="<?=$from?>" />
                </div>
                <div>
                    <label for="grammar-to-year" style="display:block; font-size:0.85em; color:#333; font-weight:600;">To year:</label>
                    <input type="text" id="grammar-to-year" style="width:8em; font-size:0.85em; box-sizing:border-box;" value="<?=$to?>" />
                </div>
                <div>
                    <label for="grammar-sort-by" style="display:block; font-size:0.85em; color:#333; font-weight:600;">Sort by:</label>
                    <select id="grammar-sort-by" class="dd-menu" style="font-size:0.85em; width:10em;">
                        <option value="rand" <?=($order == 'rand') ? 'selected' : ''?>>Random</option>
                        <option value="alpha" <?=($order == 'alpha') ? 'selected' : ''?>>Alpha</option>
                        <option value="alpha desc" <?=($order == 'alpha desc') ? 'selected' : ''?>>Alpha desc</option>
                        <option value="date" <?=($order == 'date') ? 'selected' : ''?>>Date</option>
                        <option value="date desc" <?=($order == 'date desc') ? 'selected' : ''?>>Date desc</option>
                        <option value="source" <?=($order == 'source') ? 'selected' : ''?>>Source</option>
                        <option value="source desc" <?=($order == 'source desc') ? 'selected' : ''?>>Source desc</option>
                        <option value="length" <?=($order == 'length') ? 'selected' : ''?>>Length</option>
                        <option value="length desc" <?=($order == 'length desc') ? 'selected' : ''?>>Length desc</option>
                        <option value="none" <?=($order == 'none') ? 'selected' : ''?>>None</option>
                    </select>
                </div>
            </div>
        </div>
    </form>
</center>

<script>
// Grammar view JavaScript
var grammarInfiniteScroll = null;

function grammarProviderSelected(selectElement) {
    let providerName = selectElement.value;
    console.log('Grammar provider selected:', providerName);
    
    // Fetch patterns for this provider
    updateGrammarPatterns(providerName);
}

function updateGrammarMatchCount() {
    // Get date filters if they exist
    let fromYear = document.getElementById('grammar-from-year').value;
    let toYear = document.getElementById('grammar-to-year').value;
    if( fromYear || toYear ) {
        let patternSelect = document.getElementById('grammar-pattern-select');
        let targetPattern = patternSelect.value;
        // Build URL with date filters
        let providerName = document.getElementById('grammar-provider-select').value;
        let url = 'ops/getGrammarPatterns.php?provider=' + encodeURIComponent(providerName);
        if (fromYear) url += '&from=' + encodeURIComponent(fromYear);
        if (toYear) url += '&to=' + encodeURIComponent(toYear);
    
        fetch(url)
            .then(response => response.json())
            .then(patterns => {
                console.log('Received grammar patterns:', patterns);
                
                if (patterns.length > 0 || (!patterns.error)) {
                    patterns.forEach(pattern => {
                        if( pattern.pattern_type === targetPattern ) {
                            let div = document.getElementById('grammar-matchcount');
                            div.innerHTML = pattern.count.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") + ' matching sentences';
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error fetching grammar patterns:', error);
            });
        }
}

function updateGrammarPatterns(providerName) {
    let patternSelect = document.getElementById('grammar-pattern-select');
    let goButton = document.getElementById('grammar-go-button');
    
    patternSelect.innerHTML = '<option value="">Loading patterns...</option>';
    goButton.disabled = true;
    
    // Build URL with date filters
    let url = 'ops/getGrammarPatterns.php?provider=' + encodeURIComponent(providerName);
    
    fetch(url)
        .then(response => response.json())
        .then(patterns => {
            console.log('Received grammar patterns:', patterns);
            
            patternSelect.innerHTML = '';
            
            if (patterns.length === 0 || (patterns.error)) {
                patternSelect.innerHTML = '<option value="">No patterns available</option>';
                goButton.disabled = true;
            } else {
                patterns.forEach(pattern => {
                    let option = document.createElement('option');
                    option.value = pattern.pattern_type;
                    option.textContent = pattern.pattern_type + ' (' + pattern.count + ')';
                    patternSelect.appendChild(option);
                });
                goButton.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error fetching grammar patterns:', error);
            patternSelect.innerHTML = '<option value="">Error loading patterns</option>';
            goButton.disabled = true;
        });
}

function loadGrammarResults() {
    let providerName = document.getElementById('grammar-provider-select').value;
    let patternSelect = document.getElementById('grammar-pattern-select');
    let pattern = patternSelect.value;
    let fromYear = document.getElementById('grammar-from-year').value;
    let toYear = document.getElementById('grammar-to-year').value;
    let sortBy = document.getElementById('grammar-sort-by').value;
    
    if (!pattern) {
        alert('Please select a grammar pattern');
        return;
    }
    
    // Extract total count from the selected option text (e.g., "pepeke_aike_he (329414)")
    let selectedOption = patternSelect.options[patternSelect.selectedIndex];
    let totalCount = 0;
    let match = selectedOption.text.match(/\((\d+)\)/);
    if (match) {
        totalCount = parseInt(match[1]);
    }
    
    console.log('Loading grammar results for pattern:', pattern, 'provider:', providerName, 'from:', fromYear, 'to:', toYear, 'sort:', sortBy, 'total:', totalCount);
    
    // Show and clear existing results
    let sentencesDiv = document.getElementById('sentences');
    $('#sentences').show();
    sentencesDiv.innerHTML = '';
    
    // Recreate the grammar matchcount element and display total count
    let matchcountDiv = document.createElement('div');
    let matchcountSpan = document.createElement('span');
    matchcountSpan.id = 'grammar-matchcount';
    matchcountSpan.style.color = 'white';
    matchcountSpan.innerHTML = totalCount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") + ' matching sentences';
    matchcountDiv.appendChild(matchcountSpan);
    sentencesDiv.appendChild(matchcountDiv);
    
    let brElement = document.createElement('br');
    sentencesDiv.appendChild(brElement);
    
    // Destroy existing infinite scroll if any
    if (grammarInfiniteScroll) {
        grammarInfiniteScroll.destroy();
    }
    
    // Build URL for infinite scroll with all parameters
    let url = 'ops/getGrammarMatchesHtml.php?pattern=' + encodeURIComponent(pattern) + 
              '&provider=' + encodeURIComponent(providerName) + 
              '&page={{#}}';
    
    if (fromYear) {
        url += '&from=' + encodeURIComponent(fromYear);
    }
    if (toYear) {
        url += '&to=' + encodeURIComponent(toYear);
    }
    if (sortBy) {
        url += '&order=' + encodeURIComponent(sortBy);
    }
    
    // Initialize infinite scroll
    let $container = $('.sentences').infiniteScroll({
        path: url,
        history: false,
        prefill: true,
        debug: true,
        append: 'div.hawaiiansentence',
        status: '.preloader',
        onInit: function() {
            this.on('request', function() {
                console.log('Grammar Infinite Scroll request');
            });
            this.on('load', function(body, path, response) {
                console.log('Grammar Infinite Scroll load', response);
            });
            this.on('last', function(body, path) {
                console.log('Grammar Infinite Scroll last, ' + this.loadCount + ' pages loaded');
                setTimeout(function() {
                    $(".preloader").css("display", "none");
                }, 100);
            });
            this.on('error', function(error, path, response) {
                console.log('Grammar Infinite Scroll error', error);
            });
            this.on('append', function(body, path, items, response) {
                console.log('Grammar Infinite Scroll append', items.length, 'items');
                
                // Count is already displayed from the pattern data, no need to update incrementally
                
                if (items.length < 20) {
                    console.log('Turning off loadOnScroll');
                    this.option({
                        scrollThreshold: false,
                        prefill: false,
                    });
                }
            });
        },
    });
    
    grammarInfiniteScroll = $container.data('infiniteScroll');
    updateGrammarMatchCount();
}

// Initialize grammar patterns on page load if in grammar view
$(document).ready(function() {
    if (document.getElementById('grammar-provider-select')) {
        let providerName = document.getElementById('grammar-provider-select').value;
        updateGrammarPatterns(providerName);
        // Hide the sentences div until results are loaded
        $('#sentences').hide();
        
        // Add event listeners to date fields to refresh pattern counts on Enter key
        $('#grammar-from-year, #grammar-to-year').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                let providerName = document.getElementById('grammar-provider-select').value;
                updateGrammarPatterns(providerName);
            }
        });
    }
});
</script>

<?php } ?>

 <div class="sentences" id="sentences">
     
<?php if( $doGrammar ) { ?>
    <div><span id="grammar-matchcount" style="color:white;"></span></div><br />
<?php } ?>
     
<?php if( !$word ) { ?>
    <?php if( $doSources ) { ?>

         <script>
          const delay = 1000;
          $(document).ready(function() {
              $(".context").each( function( i, obj ) {
                  $(obj).on( 'mouseenter', function() {
                      if( $(obj).prop('hovertimeout') != null ) {
                          clearTimeout( $(obj).prop('hovertimeout') );
                      }
                      let sourceid = $(obj).attr('sourceid');
                      let simplified = parseInt( $(obj).attr('simplified') );
                      let provider = $(obj).attr('provider');
                      $(obj).prop('hovertimeout', setTimeout( function() {
                          showHoverBox( sourceid, simplified, provider );
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
          
          function showHoverBox( sourceid, s, provider ) {
              let url = "rawpage.php?id=" + sourceid;
              if( s ) {
                  url += "&simplified";
              }
              if( provider ) {
                  url += "&provider=" + provider;
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
         
         <div style="margin-bottom: 1em;">
             <input type="text" id="source-search" placeholder="Filter sources..." style="width: 300px; padding: 0.5em;" />
         </div>
         
         <div style="overflow-x: auto; max-width: 100%;">
         <table id="table" class="sourcetable" style="width: 100%; table-layout: fixed;"><thead>
             <tr>
                 <th class="source-group sortable" data-sort="group" style="cursor: pointer;">Group (ID) <span class="sort-indicator"></span></th>
                 <th class="source-name sortable" data-sort="name" style="cursor: pointer;">Name <span class="sort-indicator"></span></th>
                 <th class="source-date sortable" data-sort="date" style="cursor: pointer;">Date <span class="sort-indicator"></span></th>
                 <th class="source-html">HTML</th>
                 <th class="source-plain">Plain</th>
                 <th class="source-authors sortable" data-sort="authors" style="cursor: pointer !important;">Authors <span class="sort-indicator"></span></th>
                 <th class="source-sentences text-end text-xs-right text-right sortable" data-sort="sentences" style="cursor: pointer;">Sentences <span class="sort-indicator"></span></th>
             </tr>
         </thead><tbody id="sources-tbody">

      </tbody></table>
      </div>
      
      <script>
      $(document).ready(function() {
          let searchTerm = '';
          let pageNum = 1;
          let isLoading = false;
          let hasMore = true;
          let sortColumn = '';
          let sortDirection = 'asc';
          
          function loadSources() {
              if (isLoading || !hasMore) return;
              isLoading = true;
              $('.preloader').show();
              
              let url = 'ops/getSourcesHtml.php?page=' + pageNum + '&group=<?=$groupname?>&provider=<?=$provider->getName()?>&search=' + encodeURIComponent(searchTerm);
              if (sortColumn) {
                  url += '&sort=' + sortColumn + '&dir=' + sortDirection;
              }
              
              fetch(url)
                  .then(response => response.text())
                  .then(html => {
                      console.log('Loaded HTML length:', html.length);
                      
                      // Parse HTML and extract tr elements
                      let $temp = $('<div>').html(html);
                      let $rows = $temp.find('tr.source-row');
                      
                      console.log('Found', $rows.length, 'rows');
                      
                      if ($rows.length > 0) {
                          $('#sources-tbody').append($rows);
                          
                          // Attach hover handlers to new rows
                          $rows.find('.context').each(function(i, obj) {
                              $(obj).on('mouseenter', function() {
                                  if($(obj).prop('hovertimeout') != null) {
                                      clearTimeout($(obj).prop('hovertimeout'));
                                  }
                                  let sourceid = $(obj).attr('sourceid');
                                  let simplified = parseInt($(obj).attr('simplified'));
                                  let provider = $(obj).attr('provider');
                                  $(obj).prop('hovertimeout', setTimeout(function() {
                                      showHoverBox(sourceid, simplified, provider);
                                  }, delay));
                              });
                              $(obj).on('mouseleave', function() {
                                  if($(obj).prop('hovertimeout') != null) {
                                      clearTimeout($(obj).prop('hovertimeout'));
                                      $(obj).prop('hovertimeout', null);
                                  }
                              });
                          });
                          
                          pageNum++;
                          if ($rows.length < 50) {
                              hasMore = false;
                          }
                      } else {
                          hasMore = false;
                      }
                      
                      isLoading = false;
                      $('.preloader').hide();
                  })
                  .catch(error => {
                      console.error('Error loading sources:', error);
                      isLoading = false;
                      $('.preloader').hide();
                  });
          }
          
          function sortTable(column) {
              if (sortColumn === column) {
                  // Toggle direction if same column
                  sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
              } else {
                  sortColumn = column;
                  sortDirection = 'asc';
              }
              
              // Update sort indicators
              $('.sort-indicator').text('');
              $('th[data-sort="' + column + '"] .sort-indicator').text(sortDirection === 'asc' ? ' ▲' : ' ▼');
              
              // Reload data from server with new sort
              $('#sources-tbody').empty();
              pageNum = 1;
              hasMore = true;
              loadSources();
          }
          
          // Handle column header clicks
          $('.sortable').on('click', function(e) {
              e.preventDefault();
              e.stopPropagation();
              let column = $(this).data('sort');
              console.log('Sorting by column:', column);
              sortTable(column);
          });
          
          // Load initial page
          loadSources();
          
          // Load more on scroll
          $(window).on('scroll', function() {
              if ($(window).scrollTop() + $(window).height() > $(document).height() - 400) {
                  loadSources();
              }
          });
          
          // Handle search filter
          let searchTimeout;
          $('#source-search').on('input', function() {
              clearTimeout(searchTimeout);
              searchTimeout = setTimeout(function() {
                  searchTerm = $('#source-search').val();
                  console.log('Filtering sources by: ' + searchTerm);
                  
                  // Reset and reload
                  $('#sources-tbody').empty();
                  pageNum = 1;
                  hasMore = true;
                  sortColumn = '';
                  sortDirection = 'asc';
                  $('.sort-indicator').text('');
                  loadSources();
              }, 300); // Debounce
          });
      });
      </script>
      
      <div class="preloader">
          <span></span>
          <span></span>
          <span></span>
          <span></span>
          <span></span>
      </div>

<?php 
    } else if( $doResources ) { 
        include 'resources.html';
    } else if( $doStats ) {
        define('INCLUDED_FROM_INDEX', true);
        include 'grammar_stats.php';
    } else if( !$doGrammar ) {
        // No word, not sources, not resources, not grammar - show overview
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
         if (count == -1) {
             div.innerHTML = 'Exact count not available for this search mode';
         } else {
             div.innerHTML = count.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") + ' matching sentences';
         }
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
                     if( count < 20 ) { // Less than a page
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
                         if( count < 20 ) { // Less than a page
                             reportCount( count );
                             return;
                         }
                         // More than a page of results, so have to query
                         url = 'ops/resultcount.php?search='
                               + '<?=$word?>&searchpattern=<?=$pattern?>&provider=<?=$provider->getName()?>';
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

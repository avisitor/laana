var optionsDisplayed = false

function insertcharacter(character) {
    document.getElementById("searchbar").value += character;
    document.getElementById("searchbar").focus();
}

function displaySearchOptions() {
	if (!optionsDisplayed) {
		document.getElementById('search-options').style.display = "block";
		optionsDisplayed = true
	}
	else {
		document.getElementById('search-options').style.display = "none";
		optionsDisplayed = false
	}
}

function setPattern( value ) {
	document.getElementById('search-pattern').value = value;
}

function setOrder( value ) {
	document.getElementById('order').value = value;
}

function setNoDiacriticals() {
    let state = document.getElementById('checkbox-nodiacriticals').checked;
	document.getElementById('nodiacriticals').value = (state) ? 1 : 0;
}

function changeid() {
    var theBody = document.getElementById("fadein")
    if (window.location.pathname=='/') {
        theBody.style.opacity='1'
    }
    else {
        theBody.id='nofadein'
    }
    
    // Initialize sort options based on current search pattern
    if (typeof pattern !== 'undefined') {
        updateSortOptions(pattern);
    }
}

function providerSelected(object) {
    let providerName = object.value;
    console.log('Provider selected:', providerName);
    
    // Update hidden field
    document.getElementById('provider').value = providerName;
    
    // Update the help data-provider attribute
    let helpDiv = document.getElementById('fade-help');
    if (helpDiv) {
        helpDiv.setAttribute('data-provider', providerName);
    }
    
    // Fetch available search modes for this provider
    const url = 'ops/getProviderModes.php?provider=' + encodeURIComponent(providerName);
    console.log('Fetching provider modes from URL:', url);
    fetch(url)
        .then(response => response.json())
        .then(modes => {
            console.log('Received modes:', modes);
            
            // Update the search type dropdown
            let searchTypeSelect = document.getElementById('searchtype');
            if (!searchTypeSelect) {
                console.error('Could not find searchtype element');
                return;
            }
            
            // Save current selection if possible
            let currentMode = searchTypeSelect.value;
            console.log('Current mode before update:', currentMode);
            
            searchTypeSelect.innerHTML = '';
            
            // Define explicit order for modes based on provider
            /*
            let modeOrder;
            if (providerName === 'Elasticsearch') {
                modeOrder = ['match', 'matchall', 'phrase', 'regex', 'hybrid'];
            } else if (providerName === 'Postgres') {
                modeOrder = ['exact', 'any', 'all', 'near', 'regex', 'hybrid'];
            } else {
                modeOrder = ['exact', 'any', 'all', 'regex'];
            }
            console.log('Mode order for ' + providerName + ':', modeOrder);
            
            for (let mode of modeOrder) {
                if (modes[mode]) {
                    let option = document.createElement('option');
                    option.value = mode;
                    option.text = modes[mode];
                    searchTypeSelect.add(option);
                    console.log('Added option:', mode, modes[mode]);
                }
            }
            */
           for (let mode in modes) {
                let option = document.createElement('option');
                option.value = mode;
                option.text = modes[mode];
                searchTypeSelect.add(option);
                console.log('Added option:', mode, modes[mode]);
            }
            // Restore previous selection if it exists in the new list, else select first
            let found = false;
            for (let i = 0; i < searchTypeSelect.options.length; i++) {
                if (searchTypeSelect.options[i].value === currentMode) {
                    searchTypeSelect.value = currentMode;
                    found = true;
                    break;
                }
            }
            
            if (!found && searchTypeSelect.options.length > 0) {
                let firstMode = searchTypeSelect.options[0].value;
                searchTypeSelect.value = firstMode;
                setPattern(firstMode);
                console.log('Set pattern to:', firstMode);
            }
            
            console.log('Final searchtype value:', searchTypeSelect.value);
            
            // Trigger change event on the select to ensure any listeners are notified
            searchTypeSelect.dispatchEvent(new Event('change'));
        })
        .catch(error => console.error('Error fetching provider modes:', error));
}

function patternSelected(object) {
    let value = object.value;  
    console.log(value);
    setPattern( value );
    
    // Update sort dropdown based on search mode
    updateSortOptions(value);
}

function updateSortOptions(pattern) {
    const selectOrder = document.getElementById('select-order');
    if (!selectOrder) return;
    
    const currentValue = selectOrder.value;
    
    if (pattern === 'hybriddoc') {
        // For hybrid document search, only show Relevance option
        selectOrder.innerHTML = '<option value="score">Relevance</option>';
        selectOrder.value = 'score';
        setOrder('score');
    } else {
        // Restore full set of sort options
        selectOrder.innerHTML = `
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
        `;
        // Restore previous value if it was a valid option
        selectOrder.value = currentValue;
    }
}
function orderSelected(object) {
    let value = object.value;  
    console.log(value);
    setOrder( value );
}
function fromChanged(object) {
    let value = object.value;  
    let target = document.getElementById("from");
    target.value = value;
    console.log(value);
}
function toChanged(object) {
    let value = object.value;  
    let target = document.getElementById("to");
    target.value = value;
    console.log(value);
}

function reveal() {
    document.body.classList.remove("overflow-hidden");
    let el;
    el = document.getElementById("paywall-screen");
    if( el ) {
        el.style.display = "none";
    }
    const fades = document.getElementsByClassName("modal-backdrop");
    for (let i = 0; i < fades.length; i++) {
        fades[i].style.display = "none";
    }
    el = document.getElementById("hsa-paywall-content");
    if( el ) {
        el.style.display = "block";
    }
}

function dragElement(elmnt) {
  var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
  if (document.getElementById(elmnt.id + "header")) {
    // if present, the header is where you move the DIV from:
    document.getElementById(elmnt.id + "header").onmousedown = dragMouseDown;
  } else {
    // otherwise, move the DIV from anywhere inside the DIV:
    elmnt.onmousedown = dragMouseDown;
  }

  function dragMouseDown(e) {
    e = e || window.event;
    e.preventDefault();
    // get the mouse cursor position at startup:
    pos3 = e.clientX;
    pos4 = e.clientY;
    document.onmouseup = closeDragElement;
    // call a function whenever the cursor moves:
    document.onmousemove = elementDrag;
  }

  function elementDrag(e) {
    e = e || window.event;
    e.preventDefault();
    // calculate the new cursor position:
    pos1 = pos3 - e.clientX;
    pos2 = pos4 - e.clientY;
    pos3 = e.clientX;
    pos4 = e.clientY;
    // set the element's new position:
    elmnt.style.top = (elmnt.offsetTop - pos2) + "px";
    elmnt.style.left = (elmnt.offsetLeft - pos1) + "px";
  }

  function closeDragElement() {
    // stop moving when mouse button is released:
    document.onmouseup = null;
    document.onmousemove = null;
  }
}

$(document).ready(function() {
    $('.slide').on('click', function(e){
        e.stopPropagation();
        let slider = document.getElementById('fade-help');
        if (slider) {
            // Load provider-specific help every time
            let providerName = slider.getAttribute('data-provider') || 'MySQL';
            let providerHelpUrl = 'ops/getProviderHelp.php?provider=' + encodeURIComponent(providerName);
            
            fetch(providerHelpUrl)
                .then(r => r.text())
                .then(providerHelp => {
                    slider.innerHTML = "<div id='help-div'>" + providerHelp + "</div>";
                    $('#fade-help').toggleClass('show');
                    $('#help-div').toggleClass('show');
                })
                .catch(error => {
                    console.error('Error fetching help content:', error);
                    slider.innerHTML = "<div id='help-div'><p>Error loading help content</p></div>";
                    $('#fade-help').toggleClass('show');
                    $('#help-div').toggleClass('show');
                });
        }
    });
    
    // Close help when clicking outside of it
    $(document).on('click', function(e) {
        let fadeHelp = $('#fade-help');
        if (fadeHelp.hasClass('show') && !$(e.target).closest('#fade-help').length && !$(e.target).hasClass('slide')) {
            fadeHelp.removeClass('show');
            $('#help-div').removeClass('show');
        }
        
        // Close search options when clicking outside of it
        let searchOptions = document.getElementById('search-options');
        if (optionsDisplayed && searchOptions && 
            !$(e.target).closest('#search-options').length && 
            !$(e.target).closest('.dd-button').length) {
            searchOptions.style.display = 'none';
            optionsDisplayed = false;
        }
    });
    
    el = document.getElementById( 'searchtype' );
    if( el ) {
        el.value = pattern;
    }
    el = document.getElementById( 'select-order' );
    if( el ) {
        el.value = orderBy;
    }
    const drags = document.getElementsByClassName("draggable");
    for( let i = 0; i < drags.length; i++ ) {
        dragElement( drags[i] );
    }
    reveal();
});


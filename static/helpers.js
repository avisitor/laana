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
}

function patternSelected(object) {
    let value = object.value;  
    console.log(value);
    setPattern( value );
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
    $('.slide').on('click', function(){
        $('#fade-help').toggleClass('show');
        $('#help-div').toggleClass('show');
    });
    let url = 'overview.html';
    let slider = document.getElementById('fade-help');
    if( slider ) {
        fetch( url )
            .then(response => response.text())
            .then(pageContents => {
                let parser = new DOMParser();
                const doc = parser.parseFromString(pageContents, 'text/html');
                let helpdiv = doc.getElementById('search-option-help');
                slider.innerHTML = "<div id='help-div'>" +  helpdiv.innerHTML + "</div>";
            })
            .catch(error => console.error('Error fetching content:', error));
    }
    
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


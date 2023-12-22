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
});


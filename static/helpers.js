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

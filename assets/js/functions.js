

function dologin(email, token, remember) {
    
    if(remember == "false") {
    sessionStorage.setItem('email', email);        
    sessionStorage.setItem('token', token);        
    } 
    
    if (remember == "true") {
    setCookie("email", email, 60);  
    setCookie("token", token, 60);
    }


  }


  function getToken(){
   
  if (readCookie("token") !== null) {            
      return readCookie("token");
  } 
 
  if (sessionStorage.getItem("token") !== null) {            
    return sessionStorage.getItem("token");
  }
  
  }


  function getMail(){
    if (readCookie("email") !== null) {            
      return readCookie("email");
  } 
 
  if (sessionStorage.getItem("email") !== null) {            
    return sessionStorage.getItem("email");
  } 

  }



  function isAutenticated() {
    
    if (readCookie("token") !== null) {        
        return true;
    }

    if (sessionStorage.getItem("token") !== null) {        
        return true;
    }

    return false;
  }

  function setCookie(cname, cvalue, exdays) {
    const d = new Date();
    d.setTime(d.getTime() + (exdays*24*60*60*1000));
    let expires = "expires="+ d.toUTCString();
    document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
  }


  function readCookie(name) {
    var nameEQ = name + "=";
    var ca = document.cookie.split(';');
    for (var i = 0; i < ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0) == ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
}

function eraseCookie(name) {
    setCookie(name, "", -1);
}

function logout(){
    eraseCookie("email");
    sessionStorage.removeItem("email");
    eraseCookie("token");
    sessionStorage.removeItem("token");
    
    document.location.href = "/entrar.html";
}

















function refresh() {

  document.getElementById("accDate").innerHTML = "";

	$(function(){
	

	
	// Get the messages div.
	var formMessages = $('.form-Messages');
	
	$('#emailTxt').text(getMail());



    $('#imgLoad').show();

	// IF NOT EXIST SESSION OR COOKIE WITH TOKEN REDIRECT FOR PERFIL PAGE
	if (!isAutenticated()) {
		document.location.href = "/entrar.html";
	}

  
    $.post('http://localhost:8080/accountInfo', {
        
     token:getToken() }
     
     ).done(function(response){
			
			
			
			// Make sure that the formMessages div has the 'success' class.
			$(formMessages).removeClass('error');
			$(formMessages).addClass('success');


			response.forEach(_element => {


				var bankB = numeral(_element.bankBalance).format('0,0');

				
				document.getElementById("accDate").innerHTML += "<tr scope='row'><th scope='row'></th><td><a href='#'> "+_element.account+" </a></td><td><i class='icofont-money'></i> "+bankB+" zenys no banco<small class='d-block'>"+_element.numOfCharacters+" personagens.</small></td><td>reset</td><td><a href='#do' onclick='$(`.form-Messages`).text(` `);$(`#alteraSenha-form`).show();$(`#AccountName`).val(`"+_element.account+"`);'><i class='icofont-refresh'></i> alterar</a></td><td><i class='icofont-lock'></i></td></tr><tr class='spacer'><td colspan='100'></td></tr>";

				
				
			  




			  


				

			});
			


			

			
			$('#imgLoad').hide();
			
		})
		.fail(function(data) {
			

			// Make sure that the formMessages div has the 'error' class.
			$(formMessages).removeClass('success');
			$(formMessages).addClass('error');

			// Set the message text.
			if (data.responseText !== '') {				
				$('#imgLoad').hide();
				$(formMessages).text(data.statusText);
			} else {
				console.log("point 2");
				$(formMessages).text('Oops! An error occured and your message could not be sent.');
			}
		});
			
	
	


});


}
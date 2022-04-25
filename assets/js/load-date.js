$(function() {
	

	// Get the forms.	
	var tableAccounts = $('#accDate');

	// Get the messages div.
	var formMessages = $('.form-Messages');
	
    $('#imgLoad').show();

	// IF NOT EXIST SESSION OR COOKIE WITH TOKEN REDIRECT FOR PERFIL PAGE
	if (!isAutenticated()) {
		document.location.href = "/entrar.html";
	}

  
    $.post('http://localhost:8080/account', {
        
     token:getToken() }
     
     ).done(function(response){
			
			
			
			// Make sure that the formMessages div has the 'success' class.
			$(formMessages).removeClass('error');
			$(formMessages).addClass('success');

			console.log(response);

			response.forEach(_element => {
				
				console.log(_element.username);
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
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

			frmAltpsw = $('#alteraSenha-form');

			response.forEach(_element => {


				var bankB = numeral(_element.bankBalance).format('0,0');


				document.getElementById("accDate").innerHTML += "<tr scope='row'><th scope='row'></th><td><a href='#'> "+_element.account+" </a></td><td><i class='icofont-money'></i> "+bankB+" zenys no banco<small class='d-block'>1/3 personagens.</small></td><td>reset</td><td><a href='#do' onclick='frmAltpsw.show();'><i class='icofont-refresh'></i> alterar</a></td><td><i class='icofont-lock'></i></td></tr><tr class='spacer'><td colspan='100'></td></tr>";

				
				
			  




			  


				

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
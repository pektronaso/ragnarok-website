	$(function(){
	

	
	// Get the messages div.
	var formMessages = $('.form-Messages');
	
	$('#emailTxt').text(getMail());



    $('#imgLoad').show();

	// IF NOT EXIST SESSION OR COOKIE WITH TOKEN REDIRECT FOR PERFIL PAGE
	if (!isAutenticated()) {
		document.location.href = "/entrar.html";
	}

  
    $.post('http://localhost:8080/perfil/data', {
        
     token:getToken() }
     
     ).done(function(response){
			
			
			
			// Make sure that the formMessages div has the 'success' class.
			$(formMessages).removeClass('error');
			$(formMessages).addClass('success');


			response.forEach(_element => {


				var bankB = numeral(_element.bankBalance).format('0,0');

				
				document.getElementById("accDate").innerHTML += "<tr scope='row'><th scope='row'></th><td><a href='/my-perfil.html'> "+_element.account+" </a></td><td><i class='icofont-money'></i> "+bankB+" zenys no banco<small class='d-block'>"+_element.numOfCharacters+" personagens.</small></td><td>reset</td><td><a href='#do' onclick='$(`.form-Messages`).text(` `);$(`#alteraSenha-form`).show();$(`#AccountName`).val(`"+_element.account+"`);'><i class='icofont-refresh'></i> alterar</a></td><td><i class='icofont-lock'></i></td></tr><tr class='spacer'><td colspan='100'></td></tr>";

				
				
			  




			  


				

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


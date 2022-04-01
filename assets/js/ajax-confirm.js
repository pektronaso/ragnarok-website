var url_string = window.location.href;
							var url = new URL(url_string);
							var mail = url.searchParams.get("email");
							var token = url.searchParams.get("token");							
							
							document.getElementById("mail").innerHTML =  mail;		


$.ajax({
			url: 'http://localhost:8080/account/confirm',			
			type: 'POST',
			cache: false,			
			 data: { 
        "email": mail, 
        "token": token
    },
    
    
		})
		.done(function(response) {
			
			
			$("#result").text(response);
							
							
							

		})
		.fail(function(data) {
			// Make sure that the formMessages div has the 'error' class.
			// Set the message text.
			if (data.responseText !== '') {
				$("#result").text("Erro ao confirmar E-mail!");				
			} else {
				console.log('Oops! An error occured and your message could not be sent.');
			}
		});

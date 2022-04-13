$(function() {
	

	// Get the form.
	var form = $('#addLogin-form');

	// Get the messages div.
	var formMessages = $('.form-Messages');
	

	// IF EXIST SESSION OR COOKIE WITH TOKEN REDIRECT FOR PERFIL PAGE
	if (isAutenticated()) {
		document.location.href = "/perfil.html";
	}


	// Set up an event listener for the contact form.
	$(form).submit(function(e) {
		
			
		// Stop the browser from submitting the form.
		e.preventDefault();
		
		
		// Serialize the form data.
		var formData = $(form).serializeArray();

		formData[1].value = md5(formData[1].value);
		
		// Submit the form using AJAX.
		$.ajax({
			type: 'POST',
			url: $(form).attr('action'),
			data: formData
		})
		.done(function(response) {
			
			
			
			// Make sure that the formMessages div has the 'success' class.
			$(formMessages).removeClass('error');
			$(formMessages).addClass('success');

			console.log(response[0]);
			// Verify callback and Set the message text.	
			switch(response[0]) {
				case "Login aceito, redirecionando" : 
				
				$(formMessages).text(response[0]);
				console.log(response);				

				dologin(response[2],response[1]);

				if (isAutenticated()) {
					document.location.href = "/perfil.html";
				}
				break;
				
				case "rejected" :
					$(formMessages).text(response[1]);
					console.log(response);
				break;
				
				default: 
				$(formMessages).text("Invalid callback response.");
				console.log(response);
				break;
			}


			

			// Clear the form.
			$('#email, textarea').val('');
			$('#senha, textarea').val('');
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

});
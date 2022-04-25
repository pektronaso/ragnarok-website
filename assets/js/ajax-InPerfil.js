$(function() {
	

	// Get the forms.
	var form_CreateAccount = $('#addLogin-form');
	var form_AlteraSenha = $('#alteraSenha-form');

	// Get the messages div.
	var formMessages = $('.form-Messages');
	

	// IF NOT EXIST SESSION OR COOKIE WITH TOKEN REDIRECT FOR PERFIL PAGE
	if (!isAutenticated()) {
		document.location.href = "/entrar.html";
	}


	// Set up an event listener for the contact form.
	$(form_CreateAccount).submit(function(e) {
		
			
		// Stop the browser from submitting the form.
		e.preventDefault();
		
		$('#imgLoad').show();
		
		// Serialize the form data.
		var formData = $(form_CreateAccount).serializeArray();

		formData[1].value = md5(formData[1].value);
		
		// Submit the form using AJAX.
		$.ajax({
			type: 'POST',
			url: $(form_CreateAccount).attr('action'),
			data: formData
		})
		.done(function(response) {
			
			
			
			// Make sure that the formMessages div has the 'success' class.
			$(formMessages).removeClass('error');
			$(formMessages).addClass('success');

			console.log(response[0]);

			


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
				$(formMessages).text('Oops! An error occured and your message could not be sent.');
			}
		});
			
	});

	$(form_AlteraSenha).submit(function(e) {
		
			
		// Stop the browser from submitting the form.
		e.preventDefault();
		
		
		// Serialize the form data.
		var formData = $(form_AlteraSenha).serializeArray();

		formData[1].value = md5(formData[1].value);
		
		// Submit the form using AJAX.
		$.ajax({
			type: 'POST',
			url: $(form_AlteraSenha).attr('action'),
			data: formData
		})
		.done(function(response) {
			
			
			
			// Make sure that the formMessages div has the 'success' class.
			$(formMessages).removeClass('error');
			$(formMessages).addClass('success');

			console.log(response[0]);

			


			

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
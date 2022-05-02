$(function() {
	

	// Get the forms.
	var form_CreateAccount = $('#addLogin-form');
	

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


		//get password and Hash.
		var old_password = document.getElementById("senha").value;
		var old_conpassword = document.getElementById("con_senha").value;
		
		if(old_password != old_conpassword) {				
			formMessages.text("Confirme sua senha corretamente!");
			$('#imgLoad').hide();
		} else {
		
		if(old_password.length < 6  || old_conpassword.length < 6) {				
			formMessages.text("senha precisa ser pelo menos 6 caracteres");
			$('#imgLoad').hide();
			
			
		} else {

		// Serialize the form data.
		var formData = $(form_CreateAccount).serializeArray();

		formData[1].value = md5(formData[1].value);

		formData.push({
			name: 'token',
			value: getToken() 
		  });
		
		// Submit the form using AJAX.
		$.ajax({
			type: 'PUT',
			url: $(form_CreateAccount).attr('action'),
			data: formData
		})
		.done(function(response) {
			
			
			
			// Make sure that the formMessages div has the 'success' class.
			$(formMessages).removeClass('error');
			$(formMessages).addClass('success');

			console.log(response);

			$(formMessages).text(response);
			
			refresh();

			$('#imgLoad').hide();

			form_CreateAccount.hide();
		})
		.fail(function(data) {
			

			

			// Make sure that the formMessages div has the 'error' class.
			$(formMessages).removeClass('success');
			$(formMessages).addClass('error');

			// Set the message text.
			if (data.responseText !== '') {				
				$('#imgLoad').hide();
				$(formMessages).text(data.responseText);
			} else {				
				$(formMessages).text('Oops! An error occured and your message could not be sent.');
			}
		

		});
		}}
	});


	//   INTERCEPTAO E TRATAMENTO DO FORM ALTERA SENHA INICIO

	


});
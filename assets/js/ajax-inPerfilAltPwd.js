$(function() {
	
    

	var form_AlteraSenha = $('#alteraSenha-form');
    
    

	// Get the messages div.
	var formMessages = $('.form-Messages');
	

    
    $(form_AlteraSenha).submit(function(e) {
		
			
		// Stop the browser from submitting the form.
		e.preventDefault();

		$('#imgLoad').show();
		
		// Serialize the form data.
		var formData = $(form_AlteraSenha).serializeArray();


		if (formData[1].value.length < 6  ) {

			formMessages.text("Senha ter pelo menos 6 caracteres");
			$('#imgLoad').hide();


		} else {

		formData[1].value = md5(formData[1].value);
		formData[2].value = md5(formData[2].value);


		formData.push({
			name: 'token',
			value: getToken() 
		  });

		
		
		
		
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

			console.log(response);

			formMessages.text(response);

			form_AlteraSenha.hide();
			

			// Clear the form.
			$('#Asenha, textarea').val('');
			$('#con_Asenha, textarea').val('');
			
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
	}
	});
	


});
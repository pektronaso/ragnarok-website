
/*ajax-mail.js*/
$(function() {

	// Get the form.
	var form = $('#register-form');

	// Get the messages div.
	var formMessages = $('.form-Messages');
	


	// Set up an event listener for the contact form.
	$(form).submit(function(e) {
		
			
		// Stop the browser from submitting the form.
		e.preventDefault();
		

		$('#imgLoad').show();

		
		//get password and Hash.
		    var old_password = document.getElementById("senha").value;
			var old_conpassword = document.getElementById("con_senha").value;
			
			if(old_password != old_conpassword) {				
			alert("Confirme sua senha corretamente!");
			} else {
			
			if(old_password.length < 6  || old_conpassword.length < 6) {				
				alert("senha precisa ser pelo menos 6 caracteres");
				
				
			} else {
			
			
		// Serialize the form data and Generate Password Hash.
		var formData = $(form).serializeArray();
		
		formData[1].value = md5(formData[1].value);
		
		
		// Submit the form using AJAX.
		$.ajax({
			type: 'PUT',
			url: $(form).attr('action'),
			data: formData
		})
		.done(function(response) {
			
			
			
			// Make sure that the formMessages div has the 'success' class.
			$(formMessages).removeClass('error');
			$(formMessages).addClass('success');

			// Set the message text.
			$(formMessages).text(response);
			

			// Clear the form.
			$('#register-form input,#register-form textarea').val('');
			$('#imgLoad').hide();
		})
		.fail(function(data) {
			// Make sure that the formMessages div has the 'error' class.
			$(formMessages).removeClass('success');
			$(formMessages).addClass('error');

			// Set the message text.
			if (data.responseText !== '') {
				$(formMessages).text(data.responseText);
				$('#imgLoad').hide();
			} else {
				$(formMessages).text('Oops! An error occured and your message could not be sent.');
				$('#imgLoad').hide();
			}
		});
			}}
	});

});
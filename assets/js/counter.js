$.ajax({
			type: 'GET',
			url: 'http://localhost:8080/login/count',			
		})
		.done(function(response) {
			
			document.getElementById("createdAccounts").innerHTML =  response;			

		})
		.fail(function(data) {
			// Make sure that the formMessages div has the 'error' class.
			// Set the message text.
			if (data.responseText !== '') {
				console.log(data.responseText);
			} else {
				console.log('Oops! An error occured and your message could not be sent.');
			}
		});

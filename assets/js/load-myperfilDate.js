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
				
				document.getElementById("accDate").innerHTML += "<hr><h5 class='level-text'>Conta: "+_element.account+"</h5><h5 class='level-text'>Personagens: "+_element.numOfCharacters+"</h5><h5 class='level-text'>Zenys no Banco: <i class='icofont-money'> "+bankB+"</i></h5>";


                //  aqui adicionar um novo POST que vai adicionar os characters ao inner



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
			

 
	
        $.post('http://localhost:8080/perfil/texts', {
        
            email:getMail() }
            
            ).done(function(response){
                   
                   
                   $('#perfilTitle').text(response[0]);
                   $('#perfilText').text(response[1]);

       
                   
               })
               .fail(function(data) {
                   
                   
                   if (data.responseText !== '') {				       
                       console.log(data.statusText);
                   } else {
                       console.log("error");                       
                   }
               });


               


});


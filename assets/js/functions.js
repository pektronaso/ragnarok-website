

function dologin(token, remember) {
    
    if(remember == "false") {
    sessionStorage.setItem('token', token);        
    } 
    
    if (remember == "true") {
    setCookie("token", token, 60);
    }


  }



  function isAutenticated() {
    
    if (readCookie("token") !== null) {        
        return true;
    }

    if (sessionStorage.getItem("token") !== null) {        
        return true;
    }

    return false;
  }

  function setCookie(cname, cvalue, exdays) {
    const d = new Date();
    d.setTime(d.getTime() + (exdays*24*60*60*1000));
    let expires = "expires="+ d.toUTCString();
    document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
  }


  function readCookie(name) {
    var nameEQ = name + "=";
    var ca = document.cookie.split(';');
    for (var i = 0; i < ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0) == ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
}

function eraseCookie(name) {
    setCookie(name, "", -1);
}

function logout(){
    eraseCookie("token");
    sessionStorage.removeItem("token");
    document.location.href = "/entrar.html";
}
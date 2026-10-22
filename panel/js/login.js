document.querySelector('.auth-form').addEventListener('submit', function () {
    document.getElementById('loginText').style.display = 'none';
    document.getElementById('loginSpin').style.display = 'inline-block';
    document.getElementById('loginBtn').disabled = true;
});

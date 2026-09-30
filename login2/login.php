<!DOCTYPE html>
<html lang="en">
<head>
  <script>
        function HandleLoginResponse(response)
        {
            var text = JSON.parse(response);
            document.getElementById("textResponse").textContent = "Response: " + text;
        }

        function SendLoginRequest(username, password)
        {
            var request = new XMLHttpRequest();

            request.open("POST", "login.php", true);
            request.setRequestHeader(
                "Content-Type",
                "application/x-www-form-urlencoded"
            );

            request.onreadystatechange = function ()
            {
                if (this.readyState == 4)
                {
                    if (this.status == 200)
                    {
                        HandleLoginResponse(this.responseText);
                    }
                    else
                    {
                        document.getElementById("textResponse").textContent =
                            "Could not contact the login server.";
                    }
                }
            };

		request.send(
			"type=login&uname=" + encodeURIComponent(username) +
			"&pword=" + encodeURIComponent(password)
		);
        }
    </script>
    
    <link rel="stylesheet" href="styles.css">
    <title>Login</title>
</head>

<body>

<div class="login-box">

    <h2>Login</h2>

    <form method="POST" action="login.php">

        <label>Username</label>
        <input type="text" name="uname" required>

        <label>Password</label>
        <input type="password" name="pword" required>

        <button type="submit">Login</button>
        <button type="submit">Register</button>
    </form>

</div>

</body>
</html>
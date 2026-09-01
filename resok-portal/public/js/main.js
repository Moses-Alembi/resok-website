// Login Handler
    function handleLogin() {
      window.location.href = 'login';
    }

    // Form Validation
    document.getElementById('registrationForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      
      let isValid = true;
      
      // Email validation
      const email = document.getElementById('email');
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email.value)) {
        document.getElementById('emailError').style.display = 'block';
        isValid = false;
      } else {
        document.getElementById('emailError').style.display = 'none';
      }
      
      // Mobile validation
      const mobile = document.getElementById('mobile');
      const mobileRegex = /^\+[1-9][0-9]{7,14}$/;
      if (!mobileRegex.test(mobile.value)) {
        document.getElementById('mobileError').style.display = 'block';
        isValid = false;
      } else {
        document.getElementById('mobileError').style.display = 'none';
      }
      
      // Password match validation
      const password = document.getElementById('password');
      const confirmPassword = document.getElementById('confirmPassword');
      if (password.value !== confirmPassword.value) {
        document.getElementById('passwordMatchError').style.display = 'block';
        isValid = false;
      } else {
        document.getElementById('passwordMatchError').style.display = 'none';
      }
      
      // Password strength validation
      const passwordRegex = /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,64}$/;
      if (!passwordRegex.test(password.value)) {
        alert('Password must be 8-64 characters and include at least one capital letter, one lowercase letter, and one number');
        isValid = false;
      }
      
      if (isValid) {
        const payload = {
            title: document.getElementById('title').value,
            firstName: document.getElementById('firstName').value,
            middleName: document.getElementById('middleName').value,
            surname: document.getElementById('surname').value,
            profession: document.getElementById('profession').value,
            specialization: document.getElementById('specialization').value,
            institution: document.getElementById('institution').value,
            country: document.getElementById('country').value,
            division: document.getElementById('division').value,
            county: document.getElementById('county').value,
            physicalAddress: document.getElementById('physicalAddress').value,
            payerType: document.getElementById('payerType').value,
            category: document.getElementById('category').value,
            idType: document.querySelector('input[name="idType"]:checked')?.value || 'ID',
            idNumber: document.getElementById('idNumber').value,
            email: document.getElementById('email').value,
            mobile: document.getElementById('mobile').value,
            password: document.getElementById('password').value
          };

        try {
          if (window.ResokPortal) {
            const result = await window.ResokPortal.registerMember(payload);
            const successMsg = document.getElementById('successMsg');
            successMsg.textContent = result.message || 'Registration successful.';
            successMsg.style.display = 'block';
            window.scrollTo(0, 0);
            setTimeout(() => {
              window.location.href = result.token ? 'payment' : 'login';
            }, 1200);
          } else {
            throw new Error('Registration service is not available.');
          }
        } catch (error) {
          alert(error.message || 'Registration failed. Please try again.');
        }
      }
    });

    // Real-time validation feedback
    document.getElementById('email').addEventListener('blur', function() {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (this.value && !emailRegex.test(this.value)) {
        document.getElementById('emailError').style.display = 'block';
      } else {
        document.getElementById('emailError').style.display = 'none';
      }
    });

    document.getElementById('mobile').addEventListener('blur', function() {
      const mobileRegex = /^\+[1-9][0-9]{7,14}$/;
      if (this.value && !mobileRegex.test(this.value)) {
        document.getElementById('mobileError').style.display = 'block';
      } else {
        document.getElementById('mobileError').style.display = 'none';
      }
    });

    document.getElementById('confirmPassword').addEventListener('blur', function() {
      const password = document.getElementById('password').value;
      if (this.value && this.value !== password) {
        document.getElementById('passwordMatchError').style.display = 'block';
      } else {
        document.getElementById('passwordMatchError').style.display = 'none';
      }
    });

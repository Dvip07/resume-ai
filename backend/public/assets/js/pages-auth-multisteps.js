/**
 *  Page auth register multi-steps
 */

'use strict';

// Select2 (jquery)

$(function () {
  var select2 = $('.select2');

  // select2
  if (select2.length) {
    select2.each(function () {
      var $this = $(this);
      $this.wrap('<div class="position-relative"></div>');
      $this.select2({
        placeholder: 'Select an country',
        dropdownParent: $this.parent()
      });
    });
  }
});


// Multi Steps Validation
// --------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function (e) {
  (function () {
    const stepsValidation = document.querySelector('#multiStepsValidation');
    if (typeof stepsValidation !== undefined && stepsValidation !== null) {
      // Multi Steps form
      const stepsValidationForm = stepsValidation.querySelector('#multiStepsForm');
      // Form steps
      const stepsValidationFormStep1 = stepsValidationForm.querySelector('#accountDetailsValidation');
      const stepsValidationFormStep2 = stepsValidationForm.querySelector('#personalInfoValidation');
      const stepsValidationFormStep3 = stepsValidationForm.querySelector('#billingLinksValidation');
      // Multi steps next prev button
      const stepsValidationNext = [].slice.call(stepsValidationForm.querySelectorAll('.btn-next'));
      const stepsValidationPrev = [].slice.call(stepsValidationForm.querySelectorAll('.btn-prev'));

      const multiStepsExDate = document.querySelector('.multi-steps-exp-date'),
        multiStepsCvv = document.querySelector('.multi-steps-cvv'),
        multiStepsMobile = document.querySelector('.multi-steps-mobile'),
        multiStepsPincode = document.querySelector('.multi-steps-pincode'),
        multiStepsCard = document.querySelector('.multi-steps-card');

      // Expiry Date Mask
      if (multiStepsExDate) {
        new Cleave(multiStepsExDate, {
          date: true,
          delimiter: '/',
          datePattern: ['m', 'y']
        });
      }

      // CVV
      if (multiStepsCvv) {
        new Cleave(multiStepsCvv, {
          numeral: true,
          numeralPositiveOnly: true
        });
      }

      // Mobile
      if (multiStepsMobile) {
        new Cleave(multiStepsMobile, {
          phone: true,
          phoneRegionCode: 'US'
        });
      }

      // Pincode
      if (multiStepsPincode) {
        new Cleave(multiStepsPincode, {
          delimiter: '',
          numeral: true
        });
      }


      let validationStepper = new Stepper(stepsValidation, {
        linear: true
      });

      window.pageLoadCheck = () => {
        var checkBox = document.getElementById("gst_available");
        var gst_number_div = document.getElementById("gst_number_div");
        var pancard_number_div = document.getElementById("pancard_number_div");
        if (checkBox.checked === true) {
          gst_number_div.style.display = "block";
          pancard_number_div.style.display = "none";
          document.querySelector('[name="pancard_number"]').removeAttribute('id');
          document.querySelector('[name="gst_number"]').setAttribute("id", "gst_number");
        } else {
          gst_number_div.style.display = "none";
          pancard_number_div.style.display = "block";
          document.querySelector('[name="gst_number"]').removeAttribute('id');
          document.querySelector('[name="pancard_number"]').setAttribute("id", "pancard_number");
        }

      };
      pageLoadCheck();

      // Account details
      const multiSteps1 = FormValidation.formValidation(stepsValidationFormStep1, {
        fields: {
          company_name: {
            validators: {
              notEmpty: {
                message: 'Please enter company(Firm) name'
              }
            }
          },
          contact_person_name: {
            validators: {
              notEmpty: {
                message: 'Please enter Person name'
              }
            }
          },
          contact_person_mobile: {
            validators: {
              notEmpty: {
                message: 'Please enter Person Mobile Number'
              }
            }
          },
          email: {
            validators: {
              notEmpty: {
                message: 'Please enter your email'
              },
              emailAddress: {
                message: 'Please enter valid email address'
              }
            }
          },
        },
        plugins: {
          trigger: new FormValidation.plugins.Trigger(),
          bootstrap5: new FormValidation.plugins.Bootstrap5({
            // Use this for enabling/changing valid/invalid class
            // eleInvalidClass: '',
            eleValidClass: '',
            rowSelector: '.col-sm-6'
          }),
          autoFocus: new FormValidation.plugins.AutoFocus(),
          submitButton: new FormValidation.plugins.SubmitButton()
        },
        init: instance => {
          instance.on('plugins.message.placed', function (e) {
            if (e.element.parentElement.classList.contains('input-group')) {
              e.element.parentElement.insertAdjacentElement('afterend', e.messageElement);
            }
          });
        }
      }).on('core.form.valid', function () {
        // Jump to the next step when all fields in the current step are valid
        validationStepper.next();
      });

      // Personal info
      const multiSteps2 = FormValidation.formValidation(stepsValidationFormStep2, {
        fields: {
          b_address1: {
            validators: {
              notEmpty: {
                message: 'Please enter your address line 1'
              }
            }
          },
          b_address2: {
            validators: {
              notEmpty: {
                message: 'Please enter your address line 2'
              }
            }
          },
          b_city: {
            validators: {
              notEmpty: {
                message: 'Please enter your city'
              }
            }
          },
          b_state: {
            validators: {
              notEmpty: {
                message: 'Please enter your state'
              }
            }
          },
          b_pincode: {
            validators: {
              notEmpty: {
                message: 'Please enter your pin code'
              }
            }
          },
          // pancard_number: {
          //   validators: {
          //     notEmpty: {
          //       message: 'Please enter PAN Card number'
          //     },
          //     regexp: {
          //       regexp: /^[A-Z]{5}[0-9]{4}[A-Z]{1}$/, // Regular expression for a valid PAN card
          //       message: 'Please enter a valid PAN Card number'
          //     }
          //   }
          // },
          // gst_number: {
          //   validators: {
          //     notEmpty: {
          //       message: 'Please enter GST Number'
          //     },
          //     regexp: {
          //       regexp: /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[0-9]{1}[A-Z]{1}[A-Z0-9]{1}$/, // Regular expression for a valid GST number
          //       message: 'Please enter a valid GST Number'
          //     }
          //   }
          // }
        },
        plugins: {
          trigger: new FormValidation.plugins.Trigger(),
          bootstrap5: new FormValidation.plugins.Bootstrap5({
            // Use this for enabling/changing valid/invalid class
            // eleInvalidClass: '',
            eleValidClass: '',
            rowSelector: function (field, ele) {
              // field is the field name
              // ele is the field element
              switch (field) {
                case 'multiStepsFirstName':
                  return '.col-sm-6';
                case 'multiStepsAddress':
                  return '.col-md-12';
                default:
                  return '.row';
              }
            }
          }),
          autoFocus: new FormValidation.plugins.AutoFocus(),
          submitButton: new FormValidation.plugins.SubmitButton()
        }
      }).on('core.form.valid', function () {
        // Jump to the next step when all fields in the current step are valid
        validationStepper.next();
      });

      // Social links
      const multiSteps3 = FormValidation.formValidation(stepsValidationFormStep3, {
        fields: {
          account_number: {
            validators: {
              notEmpty: {
                message: 'Please enter your account number'
              }
            }
          },
          account_name: {
            validators: {
              notEmpty: {
                message: 'Please enter your account name'
              }
            }
          },
          account_ifsc: {
            validators: {
              notEmpty: {
                message: 'Please enter your ifs code'
              }
            }
          },
          account_bank_name: {
            validators: {
              notEmpty: {
                message: 'Please enter your bank name'
              }
            }
          }
        },
        plugins: {
          trigger: new FormValidation.plugins.Trigger(),
          bootstrap5: new FormValidation.plugins.Bootstrap5({
            // Use this for enabling/changing valid/invalid class
            // eleInvalidClass: '',
            eleValidClass: '',
            rowSelector: function (field, ele) {
              // field is the field name
              // ele is the field element
              switch (field) {
                case 'multiStepsFirstName':
                  return '.col-sm-6';
                case 'multiStepsAddress':
                  return '.col-md-12';
                default:
                  return '.row';
              }
            }
          }),
          autoFocus: new FormValidation.plugins.AutoFocus(),
          submitButton: new FormValidation.plugins.SubmitButton()
        },
        init: instance => {
          instance.on('plugins.message.placed', function (e) {
            if (e.element.parentElement.classList.contains('input-group')) {
              e.element.parentElement.insertAdjacentElement('afterend', e.messageElement);
            }
          });
        }
      }).on('core.form.valid', function () {
        // You can submit the form
        // stepsValidationForm.submit()
        // or send the form data to server via an Ajax request
        // To make the demo simple, I just placed an alert

        document.getElementById('multiStepsForm').onsubmit = function (event) {
          // Perform any final validation or actions here.
          // If all is well, the form will be submitted.
          event.preventDefault();
          if (submitted) return;
          $("#overlay").fadeIn(100);
          fetch('/auth/auth-register-store', {
            method: 'POST', // or 'GET' depending on your needs
            body: new FormData(stepsValidationForm), // Serialize the form data
          }).then(response => response.json())
            .then(data => {
              //console.log(data);
              if (data.status === 'success') {
                // alert('Registration Done Successfully..!! now you can login');
                Swal.fire({
                  icon: 'success',
                  title: 'Vendor Registration!',
                  text: 'Registration Successfully Done.',
                }).then(() => {
                  $("#overlay").fadeOut(100);
                  submitted = true;
                  window.location.href = "/auth/login-cover";
                });

              } else {
                Swal.fire({
                  icon: 'warning',
                  title: 'Vendor Registration!',
                  text: data.message,
                });
              }

              // Handle the response from the server
            })
            .catch(error => {
              // console.log(error);
              toastr.error('Error:', error.message);
              // Handle any errors
            });
        }
      });

      let submitted = false;

      stepsValidationNext.forEach(item => {
        item.addEventListener('click', event => {
          // When click the Next button, we will validate the current step
          switch (validationStepper._currentIndex) {
            case 0:
              multiSteps1.validate();
              break;

            case 1:
              multiSteps2.validate();
              break;

            case 2:
              multiSteps3.validate();
              break;

            default:
              break;
          }
        });
      });

      stepsValidationPrev.forEach(item => {
        item.addEventListener('click', event => {
          switch (validationStepper._currentIndex) {
            case 2:
              validationStepper.previous();
              break;

            case 1:
              validationStepper.previous();
              break;

            case 0:

            default:
              break;
          }
        });
      });
    }
  })();
});

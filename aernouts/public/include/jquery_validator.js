$(document).ready(function() {
    if(!$("form").hasClass("novalidation")){
        var redirect = true;

        var successAttr="border";
        var successVal="1px solid #000";
        var errorAttr="border";
        var errorVal="1px solid #EE5F5B";
        
        var errorClass = "error-input";

        function formValidation(evt){
            evt.preventDefault();
            
            var formId = $(this).attr("data-form-validation");
            if(formId !== undefined && formId != ""){
                formId = "#" + formId + " ";
            }else{
                formId = "";
            }
            
            redirect = true;
            $(formId + "[rel=required]").each(validateInput);
            $(formId + "[rel=optional_email]").each(validateOptionalEmail);
            $(formId + "[rel=required_email]").each(validateEmail);
            $(formId + "[rel=optional_date]").each(optionalDate);
            $(formId + "[rel=required_date]").each(validateDate);
            $(formId + "[rel=required_time]").each(validateTime);
            $(formId + "[rel=optional_time]").each(optionalTime);
            $(formId + "[rel=required_number]").each(validateNumber);

            if(redirect){
                $("form" + formId).submit();
            }else{
                alert("Gelieve de aangeduide velden na te kijken.");
            }
        }

        $("[type=submit]").click(formValidation);
        function validateNumber(){
            var val = $(this).val();
            val = val.replace(",", "."); 
            
            if(val == "" || val == 0 || !$.isNumeric(val)){
                $(this).css(errorAttr,errorVal);
                redirect = false;
            }else{
                $(this).css(successAttr,successVal);
            }
        }
        function validateInput(){
            if($(this).val() == "" || $(this).val() == 0){
                $(this).parent().parent().addClass("error-input");
//                $(this).css(errorAttr,errorVal);
                redirect = false;
            }else{
                $(this).parent().parent().removeClass("error-input");
//                $(this).css(successAttr,successVal);
            }
        }
        function validateOptionalEmail(){
            var reg = /^([A-Za-z0-9_\-\.])+\@([A-Za-z0-9_\-\.])+\.([A-Za-z]{2,4})$/;
            if(reg.test($(this).val()) == false && $(this).val() == "") {
                $(this).parent().parent().addClass("error-input");
//                $(this).css(errorAttr,errorVal);
                redirect = false;
            }else{
                $(this).parent().parent().removeClass("error-input");
//                $(this).css(successAttr,successVal);
            }
        }
        function validateEmail(){
            var reg = /^([A-Za-z0-9_\-\.])+\@([A-Za-z0-9_\-\.])+\.([A-Za-z]{2,4})$/;
            if(reg.test($(this).val()) == false || $(this).val() == "") {
                $(this).parent().parent().addClass("error-input");
//                $(this).css(errorAttr,errorVal);
                redirect = false;
            }else{
                $(this).parent().parent().removeClass("error-input");
//                $(this).css(successAttr,successVal);
            }
        }
        function optionalDate(){
            var validformat=/^\d{2}\/\d{2}\/\d{4}$/ //Basic check for format validity
            var datum =$(this).val();
            if(datum != ""){
                if (!validformat.test(datum)){
                    $(this).css(errorAttr,errorVal);
                    redirect = false;
                } else{ //Detailed check for valid date ranges
                    var monthfield=datum.split("/")[1]
                    var dayfield=datum.split("/")[0]
                    var yearfield=datum.split("/")[2]
                    var dayobj = new Date(yearfield, monthfield-1, dayfield);
                    if ((dayobj.getMonth()+1!=monthfield)||(dayobj.getDate()!=dayfield)||(dayobj.getFullYear()!=yearfield)){
                        $(this).css(errorAttr,errorVal);
                        redirect = false;
                    }else{
                        if(monthfield > 12 || dayfield > 31){
                            $(this).css(errorAttr,errorVal);
                            redirect = false;
                        }else{
                            $(this).css(successAttr,successVal);
                        }
                    }
                }
            }else{
                $(this).css(successAttr,successVal);
            }
        }
        function validateDate(){
            var validformat=/^\d{2}\/\d{2}\/\d{4}$/ //Basic check for format validity
            var datum =$(this).val();

            if (!validformat.test(datum)){
                $(this).css(errorAttr,errorVal);
                redirect = false;
            } else{ //Detailed check for valid date ranges
                var monthfield=datum.split("/")[1]
                var dayfield=datum.split("/")[0]
                var yearfield=datum.split("/")[2]
                var dayobj = new Date(yearfield, monthfield-1, dayfield)
                if ((dayobj.getMonth()+1!=monthfield)||(dayobj.getDate()!=dayfield)||(dayobj.getFullYear()!=yearfield)){
                    $(this).css(errorAttr,errorVal);
                    redirect = false;
                }else{
                    if(monthfield > 12 || dayfield > 31){
                        $(this).css(errorAttr,errorVal);
                        redirect = false;
                    }else{
                        $(this).css(successAttr,successVal);
                    }
                }
            }
        }
        function validateTime(){
            var uur =$(this).val();
            uur = uur.split(":");

            if(uur[0] >= 00 && uur[0] <= 23 && uur[1] >= 00 && uur[1] <= 59){
                $(this).css(successAttr,successVal);
            }else{
                $(this).css(errorAttr,errorVal);
                redirect = false;
            }
        }
        function optionalTime(){
            var uur =$(this).val();
            uur = uur.split(":");

            if($(this).val() == "" || $(this).val() == 0 || $(this).val() == "00:00"){
                $(this).css(successAttr,successVal);
            }else{
                if(uur[0] >= 00 && uur[0] <= 23 && uur[1] >= 00 && uur[1] <= 59){
                    $(this).css(successAttr,successVal);
                }else{
                    $(this).css(errorAttr,errorVal);
                    redirect = false;
                }
            }
        }
    }

});
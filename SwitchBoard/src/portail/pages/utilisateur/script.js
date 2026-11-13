$(document).ready(function(){
  
  var menu = $(".menu");
  var hamburger = $(".hamburger");
  var line = $(".line");
  var menuOpen;
  
  function openMenu(){
    menu.css("left", "0px");
    line.css("background", "#FFF");
	hamburger.css("display","none");
    menuOpen = true;
  }

  function closeMenu(){
  menu.css("left", "-320px");
  line.css("background", "#28d");
  hamburger.css("display","inline");
  menuOpen = false;
}

  function toggleMenu(){
    if (menuOpen) {
      closeMenu();
    } else {
      openMenu();
    }
  }
 $('.menu-item.has-submenu').on('click', function(e) {
  e.stopPropagation();
  $(this).toggleClass('submenu-open');
  $(this).next('.submenu').toggleClass('submenu-open');
});
  hamburger.on({
    mouseenter: function(){
      openMenu();
    }
  });
  
  menu.on({
    mouseleave: function(){
      closeMenu();
    }
    
  });
  
  hamburger.on({
    click: function(){
      toggleMenu();
    }
  })
  
  

});
// ===== Scroll to Top Arrow==== 
$(window).scroll(function() {
    if ($(this).scrollTop() >= 50) {        // If page is scrolled more than 50px
        $('#return-to-top').fadeIn(200);    // Fade in the arrow
    } else {
        $('#return-to-top').fadeOut(200);   // Else fade out the arrow
    }
});

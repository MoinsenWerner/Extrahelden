const firebaseConfig = {
    apiKey: "AIzaSyDa3F06LgmUgVtOM39eoFyVZndlswPD_Eo",
    authDomain: "cube-kingdom-d7f43.firebaseapp.com",
    projectId: "cube-kingdom-d7f43",
    storageBucket: "cube-kingdom-d7f43.firebasestorage.app",
    messagingSenderId: "784131824442",
    appId: "1:784131824442:web:1037a687bc215c3d030e03",
    measurementId: "G-LT7KT5CC8Z"
  };
// Firebase initialisieren (globales Objekt)
if (!firebase.apps.length) {
    firebase.initializeApp(firebaseConfig);
  }
  
  // Auth-Instanz und Provider erstellen
  const auth = firebase.auth();
  const providerGoogle = new firebase.auth.GoogleAuthProvider();
  const providerGitHub = new firebase.auth.GithubAuthProvider();
  
  // Für globale Verfügbarkeit (optional)
  window.auth = auth;
  window.providerGoogle = providerGoogle;
  window.providerGitHub = providerGitHub;
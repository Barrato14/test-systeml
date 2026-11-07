if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
        navigator.serviceWorker.register("/sw.js")
            .then(reg => console.log("PWA registered:", reg))
            .catch(err => console.error("PWA error:", err));
    });
}

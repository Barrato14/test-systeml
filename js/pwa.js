if ("serviceWorker" in navigator) {
    window.addEventListener("load", async () => {
        try {
            // Avoid stale cached service-worker scripts when Zentrix is updated.
            const registration = await navigator.serviceWorker.register("/sw.js", {
                scope: "/",
                updateViaCache: "none"
            });

            // Ask the browser to check for a newer Zentrix service worker.
            await registration.update();

            console.log("Zentrix PWA registered:", registration);
        } catch (error) {
            console.error("Zentrix PWA registration error:", error);
        }
    });
}

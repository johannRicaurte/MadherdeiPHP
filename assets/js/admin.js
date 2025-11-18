document.addEventListener("DOMContentLoaded", function () {
  const startButton = document.getElementById("start-sync");
  const startImageButton = document.getElementById("start-image-sync");
  const progressPercentage = document.getElementById("progress-percentage");
  const progressBar = document.getElementById("progress-bar");
  const progressImagePercentage = document.getElementById(
    "progress-image-percentage"
  );
  const progressImageBar = document.getElementById("progress-image-bar");
  const syncResult = document.getElementById("sync-result");

  function getSiigoSyncProgress(interval) {
    const progressInterval = setInterval(function () {
      fetch(ajaxurl + "?action=get_siigo_sync_progress")
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            const percentage = data.data.products;
            const percentageImage = data.data.images;
            progressPercentage.textContent = percentage + "%";
            progressBar.style.width = percentage + "%";

            progressImagePercentage.textContent = percentageImage + "%";
            progressImageBar.style.width = percentageImage + "%";

            if (percentage >= 100) {
              clearInterval(progressInterval);
              syncResult.innerHTML =
                '<p class="notice notice-success">Sincronización completada</p>';
              startButton.disabled = false;
              startButton.textContent = "Sincronizar Productos";
            } else if (!interval) {
              if (percentage === 0) {
                clearInterval(progressInterval);
              } else {
                startButton.disabled = true;
                startButton.textContent = "Sincronizando en segundo plano...";
                interval = true;
              }
            }
          }
        })
        .catch((error) => console.error("Error obteniendo progreso:", error));
    }, 10000);
  }

  startButton.addEventListener("click", function (e) {
    e.preventDefault();
    startButton.disabled = true;
    startButton.textContent = "Iniciando sincronización...";

    fetch(ajaxurl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: "action=start_siigo_sync&_wpnonce=" + pluginWooAdmin.nonce,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          syncResult.innerHTML =
            '<p class="notice notice-success">' + data.data + "</p>";
          startButton.textContent = "Sincronizando en segundo plano...";
        } else {
          syncResult.innerHTML =
            '<p class="notice notice-error">' + data.data + "</p>";
          startButton.disabled = false;
          startButton.textContent = "Sincronizar Productos";
        }
      })
      .catch((error) => {
        syncResult.innerHTML =
          '<p class="notice notice-error">Error al iniciar la sincronización: ' +
          error.message +
          "</p>";
        startButton.disabled = false;
        startButton.textContent = "Sincronizar Productos";
      });
    getSiigoSyncProgress(true);
  });

  startImageButton.addEventListener("click", function (e) {
    e.preventDefault();
    startImageButton.disabled = true;
    startImageButton.textContent = "Iniciando sincronización";
    fetch(ajaxurl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: "action=start_images_sync&_wpnonce=" + pluginWooImage.nonce,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          syncResult.innerHTML =
            '<p class="notice notice-success">' + data.data + "</p>";
          startButton.textContent = "Sincronizando en segundo plano...";
        } else {
          syncResult.innerHTML =
            '<p class="notice notice-error">' + data.data + "</p>";
          startButton.disabled = false;
          startButton.textContent = "Sincronizar Productos";
        }
      })
      .catch((error) => {
        syncResult.innerHTML =
          '<p class="notice notice-error">Error al iniciar la sincronización: ' +
          error.message +
          "</p>";
        startButton.disabled = false;
        startButton.textContent = "Sincronizar Productos";
      });
    getSiigoSyncProgress(true);
  });
  getSiigoSyncProgress();
});

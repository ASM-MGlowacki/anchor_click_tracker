(function ($) {
  "use strict";

  /**
   * Dedykowana funkcja logująca. Wyświetla wiadomości w konsoli,
   * jeśli tryb debugowania jest włączony w konfiguracji przekazanej z PHP.
   */
  function pctLog() {
    if (
      window.phone_tracker_config &&
      window.phone_tracker_config.debug_mode
    ) {
      // Używa .apply, aby zachować funkcjonalność console.log z wieloma argumentami
      console.log.apply(console, ["[PCT Debug]:"].concat(Array.from(arguments)));
    }
  }

  var clickTimestamps = {};
  var CLICK_COOLDOWN_MS = 60000;

  function getCookie(name) {
    var value = "; " + document.cookie;
    var parts = value.split("; " + name + "=");
    if (parts.length === 2) return parts.pop().split(";").shift();
    return null;
  }

  function normalizePhoneNumber(rawPhoneNumber) {
    if (!rawPhoneNumber) return "";
    var digitsOnly = rawPhoneNumber.replace(/\D/g, "");
    if (digitsOnly.startsWith("48") && digitsOnly.length === 11) {
      return digitsOnly.substring(2);
    }
    return digitsOnly;
  }

  function getCalculatedSource(cookies) {
    var utmMedium = cookies.utmMedium;
    var utmSource = cookies.utmSource;
    var trafficSource = cookies.trafficSource;

    if (utmMedium === "cpc") {
      switch (utmSource) {
        case "google":
          return "google cpc";
        case "facebook":
          return "facebook cpc";
        default:
          return "inne cpc";
      }
    }

    if (utmSource === "newsletter") {
      switch (utmMedium) {
        case "email":
          return "newsletter";
        case "sms":
          return "Kampania SMS";
        default:
          return "Newsletter Inne";
      }
    }

    if (!utmMedium || utmMedium === "null") {
      if (trafficSource === "direct") {
        return "direct";
      }

      if (trafficSource && trafficSource.indexOf("instagram") > -1) {
        return "instagram organic";
      }

      if (
        trafficSource &&
        (trafficSource.indexOf("facebook") > -1 ||
          trafficSource.indexOf("linkedin") > -1 ||
          trafficSource.indexOf("messenger") > -1)
      ) {
        return "facebook organic";
      }

      if (
        trafficSource &&
        (trafficSource.indexOf("google") > -1 ||
          trafficSource.indexOf("bing") > -1 ||
          trafficSource.indexOf("chat") > -1 ||
          trafficSource.indexOf("yahoo") > -1 ||
          trafficSource.indexOf("perplexity") > -1)
      ) {
        return "organic";
      }

      return "referral";
    }

    return "inne";
  }

  function getCampaignName(domainName, phoneNumber) {
    const domainToCampaignMap = {
      "agro-siec.pl": "ASM",
      "agrocontractor.pl": "AGC",
      "cmu24.pl": "CMU",
      "stehr.pl": "Stehr",
      "agrosharing.pl": "ASH",
      "agrofinance.pl": "ASF",
      "tmc-cancela.pl": "TMC",
      "dealer.garantkotte.pl": "Garant Kotte",
      "kroger-polska.pl": "Kroger",
      "kesla-polska.pl": "Kesla",
      "hawe-polska.pl": "HAWE",
    };

    if (phoneNumber === "667114444") {
      return "ASM WWW";
    }

    const campaign = domainToCampaignMap[domainName];

    return campaign ? campaign : "nie znaleziono domeny";
  }

  function getDeviceType(width, height) {
    if (width > 1280) {
      return "desktop";
    }
    var minDimension = Math.min(width, height);
    if (minDimension < 768) {
      return "mobile";
    }
    if (minDimension >= 768) {
      return "tablet";
    }
    return "desktop";
  }

  function getFormattedLocalDateTime() {
    var now = new Date();
    var year = now.getFullYear();
    var month = ("0" + (now.getMonth() + 1)).slice(-2);
    var day = ("0" + now.getDate()).slice(-2);
    var hours = ("0" + now.getHours()).slice(-2);
    var minutes = ("0" + now.getMinutes()).slice(-2);
    var seconds = ("0" + now.getSeconds()).slice(-2);

    return year + "-" + month + "-" + day + " " + hours + ":" + minutes + ":" + seconds;
  }

  function handlePhoneLinkClick(event) {
    var linkElement = $(event.target).closest('a[href^="tel:"]');
    if (!linkElement.length) {
      return;
    }

    var clickedNumberRaw = linkElement.attr("href");
    var normalizedNumber = normalizePhoneNumber(clickedNumberRaw);

    if (!normalizedNumber) {
      pctLog("Numer telefonu jest pusty po normalizacji, przerywam.", {
        raw: clickedNumberRaw,
      });
      return;
    }

    var now = new Date().getTime();
    var lastClickTimeForThisNumber = clickTimestamps[normalizedNumber];

    if (lastClickTimeForThisNumber && now - lastClickTimeForThisNumber < CLICK_COOLDOWN_MS) {
      pctLog("Kliknięcie w ten sam numer zbyt szybko, ignoruję.", {
        number: normalizedNumber,
      });
      return;
    }

    clickTimestamps[normalizedNumber] = now;
    pctLog("Timestamp kliknięcia zapisany dla numeru:", normalizedNumber);

    var rawData = {
      url: window.location.href,
      clickedNumber: clickedNumberRaw,
      screenWidth: window.screen.width,
      screenHeight: window.screen.height,
      cookies: {
        trafficSource: getCookie("pysTrafficSource"),
        utmMedium: getCookie("pys_utm_medium"),
        utmSource: getCookie("pys_utm_source"),
        landingPage: getCookie("pys_landing_page"),
      },
    };

    var domain = window.location.hostname.replace(/^www\./, "");

    var derivedData = {
      normalizedPhoneNumber: normalizedNumber,
      calculatedSource: getCalculatedSource(rawData.cookies),
      deviceType: getDeviceType(rawData.screenWidth, rawData.screenHeight),
      timestamp: getFormattedLocalDateTime(),
      domain: domain,
      campaignName: getCampaignName(domain, normalizedNumber),
    };

    var finalPayload = {
      Data: derivedData.timestamp,
      Źródło: derivedData.calculatedSource,
      "URL na którym kliknięto": rawData.url,
      "Numer w który kliknięto": derivedData.normalizedPhoneNumber,
      "Szerokość ekranu": rawData.screenWidth,
      "Wysokość ekranu": rawData.screenHeight,
      Urządzenie: derivedData.deviceType,
      Domena: derivedData.domain,
      pys_traffic_source: rawData.cookies.trafficSource,
      pys_utm_medium: rawData.cookies.utmMedium,
      pys_utm_source: rawData.cookies.utmSource,
      pys_landing_page: rawData.cookies.landingPage,
      "Spółka (kampania)": derivedData.campaignName,
      "Wersja skryptu": "1.6.0" 
    };

    pctLog("Przygotowano dane do wysłania:", finalPayload);

    $.ajax({
      url: phone_tracker_config.ajax_url,
      type: "POST",
      data: {
        action: "track_phone_click",
        security: phone_tracker_config.nonce,
        payload: finalPayload,
      },
      success: function (response) {
        pctLog("Dane telefonu wysłane pomyślnie.", response);
      },
      error: function (jqXHR, textStatus, errorThrown) {
        pctLog("Błąd AJAX podczas wysyłania danych telefonu.", {
          status: textStatus,
          error: errorThrown,
          response: jqXHR.responseText,
        });
      },
    });
  }

  function handleEmailLinkClick(event) {
    var anchor = $(event.target).closest('a');
    if (!anchor.length) {
      return;
    }
    var href = anchor.attr("href");
    if (!href || typeof href !== "string") {
      return;
    }
    var hrefLower = href.toLowerCase();
    if (!hrefLower.startsWith("mailto:")) {
      return;
    }
    var rawEmailPart = href.substring(7).split("?")[0];
    try {
      rawEmailPart = decodeURIComponent(rawEmailPart);
    } catch (e) {}
    var email = rawEmailPart.trim().toLowerCase();

    if (!email) {
      pctLog("Adres email jest pusty po przetworzeniu, przerywam.", {
        href: href,
      });
      return;
    }

    var now = new Date().getTime();
    var lastClickTimeForThisEmail = clickTimestamps[email];

    if (lastClickTimeForThisEmail && now - lastClickTimeForThisEmail < CLICK_COOLDOWN_MS) {
      pctLog("Kliknięcie w ten sam email zbyt szybko, ignoruję.", { email: email });
      return;
    }
    
    clickTimestamps[email] = now;
    pctLog("Timestamp kliknięcia zapisany dla emaila:", email);

    var rawData = {
      url: window.location.href,
      clickedEmail: email,
      screenWidth: window.screen.width,
      screenHeight: window.screen.height,
      cookies: {
        trafficSource: getCookie("pysTrafficSource"),
        utmMedium: getCookie("pys_utm_medium"),
        utmSource: getCookie("pys_utm_source"),
        landingPage: getCookie("pys_landing_page"),
      },
    };

    var domain = window.location.hostname.replace(/^www\./, "");

    var derivedData = {
      calculatedSource: getCalculatedSource(rawData.cookies),
      deviceType: getDeviceType(rawData.screenWidth, rawData.screenHeight),
      timestamp: getFormattedLocalDateTime(),
      domain: domain,
      campaignName: getCampaignName(domain, null),
    };

    var finalPayload = {
      "Typ zdarzenia": "email",
      Data: derivedData.timestamp,
      Źródło: derivedData.calculatedSource,
      "URL na którym kliknięto": rawData.url,
      "Adres email w który kliknięto": rawData.clickedEmail,
      "Szerokość ekranu": rawData.screenWidth,
      "Wysokość ekranu": rawData.screenHeight,
      Urządzenie: derivedData.deviceType,
      Domena: derivedData.domain,
      pys_traffic_source: rawData.cookies.trafficSource,
      pys_utm_medium: rawData.cookies.utmMedium,
      pys_utm_source: rawData.cookies.utmSource,
      pys_landing_page: rawData.cookies.landingPage,
      "Spółka (kampania)": derivedData.campaignName,
      "Wersja skryptu": "1.6.0"
    };

    pctLog("Przygotowano dane email do wysłania:", finalPayload);

    $.ajax({
      url: phone_tracker_config.ajax_url,
      type: "POST",
      data: {
        action: "track_email_click",
        security: phone_tracker_config.nonce,
        payload: finalPayload,
      },
      success: function (response) {
        pctLog("Dane email wysłane pomyślnie.", response);
      },
      error: function (jqXHR, textStatus, errorThrown) {
        pctLog("Błąd AJAX podczas wysyłania danych email.", {
          status: textStatus,
          error: errorThrown,
          response: jqXHR.responseText,
        });
      },
    });
  }

  function initPhoneLinkTracker() {
    if (typeof phone_tracker_config === "undefined") {
      console.error(
        "[PCT Error]: Obiekt konfiguracyjny 'phone_tracker_config' nie został znaleziony. Skrypt nie będzie działać."
      );
      return;
    }
    pctLog(
      "Inicjalizacja trackera. Konfiguracja załadowana:",
      phone_tracker_config
    );
    $(document).on("click", handlePhoneLinkClick);
    $(document).on("click", handleEmailLinkClick);
  }

  $(document).ready(function () {
    pctLog("Dokument gotowy.");
    initPhoneLinkTracker();
  });
})(jQuery);
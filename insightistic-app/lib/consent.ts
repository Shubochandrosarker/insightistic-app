const KEY = "ins_marketing_consent";

export function getMarketingConsent(): boolean | null {
  if (typeof window === "undefined") return null;
  try {
    const value = window.localStorage.getItem(KEY);
    return value === "accepted" ? true : value === "rejected" ? false : null;
  } catch {
    return null;
  }
}

export function setMarketingConsent(value: boolean): void {
  try {
    window.localStorage.setItem(KEY, value ? "accepted" : "rejected");
  } catch {
    // Private browsing/storage restrictions must not block the product.
  }
}

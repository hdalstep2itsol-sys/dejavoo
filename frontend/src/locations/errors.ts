import { ApiError } from "@/lib/api";

export function requestErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) {
    return "The request could not be completed. Please try again.";
  }

  const validationMessage = Object.values(error.errors).flat()[0];
  return validationMessage ?? error.message;
}

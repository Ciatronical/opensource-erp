class ApiError extends Error {
    constructor(name, code, message) {
        super(message);
        this.name = name;
        this.code = code;
    }
}

class PermissionDeniedError extends Error {
    constructor(permissionName, message) {
        super('PermissionDeniedError', permissionName, message);
    }
}

export { ApiError, PermissionDeniedError };
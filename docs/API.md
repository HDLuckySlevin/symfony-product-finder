# API Documentation

This project provides a set of JSON endpoints for searching products and managing the product index. The examples below mirror the Postman collection used by the frontend.

All endpoints require an `X-API-Key` header. Replace `<API_KEY>` with the key defined in your environment.

Base URL in development: `https://symfony-product-finder.ddev.site/`

## Search Endpoints

### `POST /api/search/text`
Search for products using a text query.

Example request body:
```json
{
  "message": "smartphone"
}
```

The response contains a recommendation and the most similar products.

### `POST /api/search/image`
Upload an image and search based on the generated description.

Form field: `image` (file upload)

### `POST /api/search/audio`
Upload an audio file which is transcribed and used as the search query.

Form field: `audio` (file upload)

## Product Management

### `POST /api/products`
Upload a single product as JSON. Requires the `X-API-Key` header.

Example payload:
```json
{
  "id": 2,
  "name": "Example Phone",
  "sku": "XYZ-12345",
  "description": "A powerful smartphone with OLED display.",
  "brand": "TechCorp",
  "category": "Electronics",
  "price": 699.99,
  "specifications": { "Display": "6.1 inch" },
  "features": ["5G", "Face ID"],
  "imageUrl": "https://example.com/images/product.jpg",
  "rating": 4.5,
  "stock": 120
}
```

Returns `{ "success": true }` on success.

### `DELETE /api/products/{id}`
Remove the vectors for a product from the index.

Returns `{ "success": true }` when deletion was successful.

## Postman Collection

A sanitised Postman collection is available in [docs/api/postman_collection.json](api/postman_collection.json).

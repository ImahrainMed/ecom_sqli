# Admin Product Management

## Objective

This document explains the deletion strategy used for the admin product management feature.

## Deletion strategy

For this task, the chosen strategy is **restrict delete**.

When an admin tries to delete a product, the application first checks if the product already exists in historical order items.

If the product is already linked to at least one `OrderItem`, the product is not deleted.

This avoids breaking historical order data, because old orders still need to display the product that was purchased at the time of the order.

## Reason

A product can be linked to existing order history through the `order_item` table.

If the product was physically deleted from the database, historical order details could become incomplete or broken.

For this reason, the application prevents deletion of products that are already used in orders.

## Current behavior

- If the product has no related `OrderItem`, it can be deleted.
- If the product has at least one related `OrderItem`, deletion is blocked and an error message is shown to the admin.

## Alternative

Another possible approach would be soft delete, for example by adding a `deletedAt` field to the `Product` entity.

That approach was not used in this iteration to avoid adding a schema change and to keep the implementation simple.
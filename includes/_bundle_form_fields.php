<?php
/* Shared form fields for Add/Edit Bundle modal (food_menus.php) */
?>
<div class="row">
    <div class="col-md-7 mb-3">
        <label class="form-label">Bundle Name <span class="text-danger">*</span></label>
        <input type="text" name="bundle_name" class="form-control" required placeholder="e.g., Dessert Station">
    </div>
    <div class="col-md-5 mb-3">
        <label class="form-label">Type</label>
        <select name="bundle_type" class="form-select">
            <option value="Food Bundle">Food Bundle</option>
            <option value="Add-on">Add-on</option>
            <option value="Service">Service</option>
        </select>
    </div>
</div>
<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Price (₱) <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0" name="price" class="form-control" required placeholder="500.00">
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Price Mode</label>
        <select name="price_mode" class="form-select">
            <option value="flat">Flat Fee</option>
            <option value="per_guest">Per Guest</option>
        </select>
    </div>
</div>
<div class="mb-3">
    <label class="form-label">Guests per Table / Order</label>
    <input type="number" name="guests_covered" class="form-control" min="1" max="1000" step="1" placeholder="e.g., 10">
    <small class="text-muted">How many guests one order serves, shown in Step 3 of the booking wizard. Leave blank for Per Guest prices or when it does not apply.</small>
</div>
<div class="mb-3">
    <label class="form-label">Order Note</label>
    <input type="text" name="order_note" class="form-control" maxlength="150" placeholder="e.g., Good for 10 pax, or Minimum 20 packs">
</div>
<div class="mb-3">
    <label class="form-label">Inclusions</label>
    <textarea name="inclusions" class="form-control" rows="6" placeholder="One inclusion per line, for example:&#10;Pork asado&#10;Chopsuey&#10;4 rice"></textarea>
    <small class="text-muted">Type one inclusion per line. Each line is saved as its own record and shown as a bullet.</small>
</div>
<div class="mb-2">
    <label class="form-label">Bundle Photo</label>
    <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewPhoto(this, this.closest('form').querySelector('.photo-preview').id)">
    <small class="text-muted">JPG, PNG, GIF, WebP</small>
    <img class="photo-preview" id="bundlePreview_<?php echo uniqid(); ?>" style="display:none;" alt="Preview">
</div>

# Hawaiian Document Review Web App

A modern, responsive web application for reviewing processed Hawaiian documents from the noiiolelo.org API.

## Features

### Navigation
- **Previous/Next buttons**: Navigate through documents sequentially
- **Source ID search**: Type-ahead search with autocomplete for finding specific documents
- **Keyboard shortcuts**:
  - Arrow keys (←/→) or H/L keys to navigate
  - `/` to focus the search input
  - Enter to navigate to typed source ID
  - Escape to close search dropdown

### Touch/Swipe Support
- **Touch devices**: Swipe left/right to navigate between documents
- **Desktop**: Click and drag left/right to navigate
- Prevents accidental navigation during vertical scrolling

### Document Display
- **Responsive layout**: Works on desktop, tablet, and mobile
- **Document metadata**: Shows title, group name, and source ID
- **Progress indicator**: Shows current position in document collection
- **Smooth transitions**: Loading states and content transitions
- **Auto-scrolling**: Content scrolls to top when changing documents

### Search & Discovery
- **Typeahead search**: Search by source ID, title, or group name
- **Dropdown suggestions**: Shows matching documents with metadata
- **Keyboard navigation**: Use Enter/Escape in search field

## API Integration

The app integrates with the noiiolelo.org API:
- **Document list**: `https://noiiolelo.org/api.php/sources?details`
- **Document content**: `https://noiiolelo.org/api.php/source/{sourceid}/plain`

## Files

- `index.html` - Main HTML structure
- `styles.css` - Modern CSS styling with gradients and animations
- `script.js` - JavaScript application logic and API integration

## Usage

1. Open `index.html` in a web browser
2. Wait for documents to load
3. Use navigation controls or swipe to browse documents
4. Use the search field to find specific documents by ID, title, or group

## Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Mobile browsers with touch support
- Requires JavaScript enabled
- Uses modern CSS features (Grid, Flexbox, CSS Variables)
